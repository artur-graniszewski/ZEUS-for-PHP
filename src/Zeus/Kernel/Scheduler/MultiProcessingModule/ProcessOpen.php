<?php

namespace Zeus\Kernel\Scheduler\MultiProcessingModule;

use Zend\EventManager\EventManagerInterface;
use Zeus\IO\Stream\SelectionKey;
use Zeus\Kernel\IpcServer\IpcEvent;
use Zeus\Kernel\Scheduler\Exception\SchedulerException;
use Zeus\Kernel\Scheduler\MultiProcessingModule\ProcessOpen\ProcessOpenBridge;
use Zeus\Kernel\Scheduler\MultiProcessingModule\ProcessOpen\ProcessOpenBridgeInterface;
use Zeus\Kernel\Scheduler\SchedulerEvent;
use Zeus\Kernel\Scheduler\WorkerEvent;
use Zeus\IO\Exception\IOException;
use Zeus\IO\Stream\PipeStream;
use Zeus\IO\Stream\Selector;

use function escapeshellarg;
use function strrpos;
use function substr;
use function array_search;
use function fwrite;
use function fclose;
use function fopen;

final class ProcessOpen extends AbstractProcessModule implements SeparateAddressSpaceInterface
{
    protected $stdout;

    protected $stderr;

    /** @var PipeStream[] */
    protected $stdOutStreams = [];

    /** @var PipeStream[] */
    protected $stdErrStreams = [];

    protected $pipeBuffer = [];

    /** @var ProcessOpenBridgeInterface */
    private static $procOpenBridge;

    protected static function getProcessBridge() : ProcessOpenBridgeInterface
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

            $errorMessage = sprintf("proc_open() and proc_get_status() are required by %s but disabled in PHP",
                $className);
        }

        return $isSupported;
    }

    /**
     * ProcessOpen constructor.
     */
    public function __construct()
    {
        $this->stdout = static::getProcessBridge()->getStdOut();
        $this->stderr = static::getProcessBridge()->getStdErr();

        if (!$this->stdout) {
            $this->stdout = @fopen('php://stdout', 'w');
        }

        if (!$this->stderr) {
            $this->stderr = @fopen('php://stderr', 'w');
        }
    }

    public function __destruct()
    {
        @fclose($this->stdout);
        @fclose($this->stderr);
    }

    public function attach(EventManagerInterface $eventManager)
    {
        $eventManager->getSharedManager()->attach('*', IpcEvent::EVENT_HANDLING_MESSAGES, function($e) { $this->onIpcSelect($e); }, -9000);
        $eventManager->getSharedManager()->attach('*', IpcEvent::EVENT_STREAM_READABLE, function($e) { $this->checkWorkerOutput($e); }, -9000);
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
                $this->getWrapper()->raiseWorkerExitedEvent($pid, $pid, 1);
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
                $this->stdErrStreams[$uid]->close();
            }
        } catch (IOException $ex) {

        }

        try {
            if (isset($this->stdOutStreams[$uid])) {
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

        $type = $event->getParam('server') ? 'scheduler' : 'worker';
        $serviceName = escapeshellarg($event->getTarget()->getConfig()->getServiceName());
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
            $this->stdOutStreams[$pid] = new PipeStream($pipes[1]);
            $this->stdOutStreams[$pid]->setBlocking(false);
            $this->stdErrStreams[$pid] = new PipeStream($pipes[2]);
            $this->stdErrStreams[$pid]->setBlocking(false);
        } catch (IOException $ex) {

        }
        $this->pipeBuffer[$pid] = ['stdout' => '', 'stderr' => ''];

        return $pid;
    }

    private function onIpcSelect(IpcEvent $event)
    {
        /** @var Selector $selector */
        $selector = $event->getParam('selector');

        foreach ($this->workers as $uid => $worker) {
            if ($this->stdOutStreams[$uid]->isReadable()) {
                $selector->register($this->stdOutStreams[$uid], SelectionKey::OP_READ);
            }

            if ($this->stdErrStreams[$uid]->isReadable()) {
                $selector->register($this->stdErrStreams[$uid], SelectionKey::OP_READ);
            }
        }
    }

    private function checkWorkerOutput(IpcEvent $event)
    {
        /** @var PipeStream $stream */
        $stream = $event->getParam('stream');

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

    public function onKernelStart(SchedulerEvent $event)
    {
        // TODO: Implement onKernelStart() method.
    }

    public function onKernelLoop(SchedulerEvent $event)
    {
        // TODO: Implement onKernelLoop() method.
    }

    public function onSchedulerStop(SchedulerEvent $event)
    {
        // TODO: Implement onSchedulerStop() method.
    }

    public function onWorkerExit(WorkerEvent $event)
    {
        // TODO: Implement onWorkerExit() method.
    }

    public function onSchedulerInit(SchedulerEvent $event)
    {
        // TODO: Implement onSchedulerInit() method.
    }

    public function onSchedulerLoop(SchedulerEvent $event)
    {
        // TODO: Implement onSchedulerLoop() method.
    }

    public function onWorkerLoop(WorkerEvent $event)
    {
        // TODO: Implement onWorkerLoop() method.
    }
}