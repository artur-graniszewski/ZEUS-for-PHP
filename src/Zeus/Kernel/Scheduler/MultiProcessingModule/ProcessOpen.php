<?php

namespace Zeus\Kernel\Scheduler\MultiProcessingModule;

use Zend\EventManager\EventManagerInterface;
use Zeus\IO\Stream\AbstractStreamSelector;
use Zeus\IO\Stream\SelectionKey;
use Zeus\Kernel\SchedulerInterface;
use Zeus\Kernel\Scheduler\Exception\SchedulerException;
use Zeus\Kernel\Scheduler\MultiProcessingModule\ProcessOpen\ProcessOpenBridge;
use Zeus\Kernel\Scheduler\MultiProcessingModule\ProcessOpen\ProcessOpenBridgeInterface;
use Zeus\Kernel\Scheduler\SchedulerEvent;
use Zeus\Kernel\Scheduler\WorkerEvent;
use Zeus\IO\Exception\IOException;
use Zeus\IO\Stream\PipeStream;
use Zeus\IO\Stream\Selector;

use function escapeshellarg;
use function basename;
use function strrpos;
use function str_replace;
use function sprintf;
use function substr;
use function array_search;
use function json_encode;
use function in_array;
use function fwrite;
use function fclose;
use function fopen;
use function defined;

/**
 * Class ProcessOpenWithPipe
 * @package Zeus\Kernel\Scheduler\MultiProcessingModule
 */
final class ProcessOpen extends AbstractProcessModule implements SeparateAddressSpaceInterface
{
    private $stdout;

    private $stderr;

    /** @var PipeStream[] */
    private $stdOutStreams = [];

    /** @var PipeStream[] */
    private $stdErrStreams = [];

    private $pipeBuffer = [];

    /** @var ProcessOpenBridgeInterface */
    private static $procOpenBridge;

    /** @var Selector */
    private $workerSelector;

    private static function getProcessBridge() : ProcessOpenBridgeInterface
    {
        if (!isset(static::$procOpenBridge)) {
            static::$procOpenBridge = new ProcessOpenBridge();
        }

        return static::$procOpenBridge;
    }


    public static function setProcessBridge(ProcessOpenBridgeInterface $bridge)
    {
        static::$procOpenBridge = $bridge;
    }

    public static function isSupported(& $errorMessage = '') : bool
    {
        $isSupported = static::getProcessBridge()->isSupported();

        if (!$isSupported) {
            $className = basename(str_replace('\\', '/', static::class));

            if (defined("HHVM_VERSION")) {
                $errorMessage = sprintf("HHVM does not support pipes as IPC transport",
                    $className);
            } else {
                $errorMessage = sprintf("proc_open() and proc_get_status() are required by %s but disabled in PHP",
                    $className);
            }
        }

        return $isSupported;
    }

    /**
     * ProcessOpen constructor.
     */
    public function __construct()
    {
        if (!$this->stdout) {
            $this->stdout = @fopen(static::getProcessBridge()->getStdOut(), 'w');
        }

        if (!$this->stderr) {
            $this->stderr = @fopen(static::getProcessBridge()->getStdErr(), 'w');
        }

        $this->workerSelector = new Selector();
    }

    public function __destruct()
    {
        @fclose($this->stdout);
        @fclose($this->stderr);
    }

    public function attach(EventManagerInterface $eventManager)
    {
        $eventManager->attach(SchedulerEvent::EVENT_START, function(SchedulerEvent $event) {
            $event->getScheduler()->observeSelector($this->workerSelector, function() {$this->checkWorkerOutput($this->workerSelector);}, function() {}, 1000);
        }, -9000);

        $eventManager->attach(SchedulerEvent::INTERNAL_EVENT_KERNEL_START, function(SchedulerEvent $event) {
            $event->getScheduler()->observeSelector($this->workerSelector, function() {$this->checkWorkerOutput($this->workerSelector);}, function() {}, 1000);
        }, -9000);
    }

    public function onWorkerTerminated(WorkerEvent $event)
    {
        $this->cleanProcessPipes($event->getWorker()->getUid());
    }

    private function flushBuffers(int $uid, bool $forceFlush)
    {
        foreach (['stdout', 'stderr'] as $type) {
            if (!isset($this->pipeBuffer[$uid][$type])) {
                continue;

            }

            if ($forceFlush) {
                fwrite($this->$type, $this->pipeBuffer[$uid][$type]);
                $this->pipeBuffer[$uid][$type] = '';
            } else {
                if (($pos = strrpos($this->pipeBuffer[$uid][$type], "\n")) !== false) {
                    fwrite($this->$type, substr($this->pipeBuffer[$uid][$type], 0, $pos + 1));
                    $this->pipeBuffer[$uid][$type] = substr($this->pipeBuffer[$uid][$type], $pos + 1);
                }
            }
        }
    }

    public function onWorkersCheck(SchedulerEvent $event)
    {
        parent::onWorkersCheck($event);

        foreach ($this->workers as $pid => $worker) {
            $status = static::getProcessBridge()->getProcStatus($worker['resource']);

            if (!$status['running']) {
                $this->cleanProcessPipes($pid);
                $this->getDecorator()->raiseWorkerExitedEvent($pid, $pid, 1);
            }
        }
    }

    private function cleanProcessPipes($uid)
    {
        if (!isset($this->stdOutStreams[$uid])) {
            return;
        }

        // check stdOut and stdErr...
        $selector = new Selector();
        foreach (['stdout' => $this->stdOutStreams[$uid], 'stderr' => $this->stdErrStreams[$uid]] as $type => $stream) {
            /** @var PipeStream $stream */
            if (!$stream->isReadable()) {
                // @todo: handle this somehow here?
                continue;
            }
            $key = $stream->register($selector, SelectionKey::OP_READ);
            $key->attach((object) ['type' => $type]);
        }

        if ($selector->select(0) > 0) {
            foreach ($selector->getSelectionKeys() as $key) {
                $type = $key->getAttachment()->type;
                $this->pipeBuffer[$uid][$type] .= $key->getStream()->read();
            }
        }

        $this->flushBuffers($uid, true);
        unset ($this->pipeBuffer[$uid]);

        try {
            if (isset($this->stdErrStreams[$uid])) {
                $this->workerSelector->unregister($this->stdErrStreams[$uid]);
                $this->stdErrStreams[$uid]->close();
            }
        } catch (IOException $ex) {

        }

        try {
            if (isset($this->stdOutStreams[$uid])) {
                $this->workerSelector->unregister($this->stdOutStreams[$uid]);
                $this->stdOutStreams[$uid]->close();
            }
        } catch (IOException $ex) {

        }

        unset ($this->stdErrStreams[$uid]);
        unset ($this->stdOutStreams[$uid]);
        $tmpArray = $this->stdErrStreams;
        $this->stdErrStreams = $tmpArray;

        $tmpArray = $this->stdOutStreams;
        $this->stdOutStreams = $tmpArray;
        @fclose($this->workers[$uid]['resource']);
        unset ($this->workers[$uid]);
    }

    protected function createProcess(WorkerEvent $event) : int
    {
        $descriptors = [
            0 => ['pipe', 'r'], // stdin
            1 => ['pipe', 'w'], // stdout
            2 => ['pipe', 'w'], // stderr
        ];

        $pipes = [];

        $phpExecutable = isset($_SERVER['_']) ? $_SERVER['_'] : PHP_BINARY;

        $applicationPath = $_SERVER['PHP_SELF'];

        $type = $event->getParam(SchedulerInterface::WORKER_SERVER) ? 'scheduler' : 'worker';
        $serviceName = escapeshellarg($event->getWorker()->getServiceName());
        $startParams = escapeshellarg(json_encode($event->getParams()));

        $command = sprintf("exec %s %s zeus %s %s %s", $phpExecutable, $applicationPath, $type, $serviceName, $startParams);

        $process = static::getProcessBridge()->procOpen($command, $descriptors, $pipes, getcwd(), $_ENV, []);
        if ($process === false) {
            throw new SchedulerException("Could not create a descendant process", SchedulerException::WORKER_NOT_STARTED);
        }

        $status = static::getProcessBridge()->getProcStatus($process);
        $pid = $status['pid'];

        $this->workers[$pid] = [
            'resource' => $process,
        ];

        try {
            $stdOutStream = new PipeStream($pipes[1]);
            $stdOutStream->setBlocking(false);
            $stdOutStream->register($this->workerSelector, SelectionKey::OP_READ);
            $stdErrStream = new PipeStream($pipes[2]);
            $stdErrStream->setBlocking(false);
            $stdErrStream->register($this->workerSelector, SelectionKey::OP_READ);

            $this->stdOutStreams[$pid] = $stdOutStream;
            $this->stdErrStreams[$pid] = $stdErrStream;
        } catch (IOException $ex) {

        }
        $this->pipeBuffer[$pid] = ['stdout' => '', 'stderr' => ''];

        return $pid;
    }

    private function checkWorkerOutput(AbstractStreamSelector $selector)
    {
        foreach ($selector->getSelectionKeys() as $key) {
            /** @var PipeStream $stream */
            $stream = $key->getStream();

            if (in_array($stream, $this->stdOutStreams)) {
                $outputType = 'stdout';
                $uid = array_search($stream, $this->stdOutStreams);
            } else if (in_array($stream, $this->stdErrStreams)) {
                $outputType = 'stderr';
                $uid = array_search($stream, $this->stdErrStreams);
            } else {
                return;
            }

            try {
                while ($data = $stream->read()) {
                    $this->pipeBuffer[$uid][$outputType] .= $data;
                }
            } catch (IOException $exception) {
            }

            $this->flushBuffers($uid, false);
        }
    }

    public function onKernelLoop(SchedulerEvent $event)
    {
        if (static::getPcntlBridge()->isSupported()) {
            static::getPcntlBridge()->pcntlSignalDispatch();
        }
    }
}