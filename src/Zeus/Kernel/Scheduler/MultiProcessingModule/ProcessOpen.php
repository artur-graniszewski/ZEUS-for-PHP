<?php

namespace Zeus\Kernel\Scheduler\MultiProcessingModule;

use Zend\EventManager\EventInterface;
use Zend\EventManager\EventManagerInterface;
use Zeus\Kernel\IpcServer\IpcEvent;
use Zeus\Kernel\Scheduler\Exception\SchedulerException;
use Zeus\Kernel\Scheduler\MultiProcessingModule\PosixProcess\PcntlBridge;
use Zeus\Kernel\Scheduler\MultiProcessingModule\PosixProcess\PosixProcessBridgeInterface;
use Zeus\Kernel\Scheduler\WorkerEvent;
use Zeus\Kernel\Scheduler\SchedulerEvent;
use Zeus\Networking\Exception\StreamException;
use Zeus\Networking\Stream\PipeStream;
use Zeus\Networking\Stream\Selector;
use Zeus\ServerService\ManagerEvent;

final class ProcessOpen extends AbstractModule implements MultiProcessingModuleInterface, SeparateAddressSpaceInterface
{
    /** @var EventManagerInterface */
    protected $events;

    /** @var int Parent PID */
    public $ppid;

    /** @var PosixProcessBridgeInterface */
    protected static $pcntlBridge;

    protected $workers = [];

    protected $stdout;

    protected $stderr;

    /** @var PipeStream[] */
    protected $stdOutStreams = [];

    /** @var PipeStream[] */
    protected $stdErrStreams = [];

    protected $pipeBuffer = [];

    /**
     * @param bool $throwException
     * @return bool
     * @throws \Exception
     */
    public static function isSupported($throwException = false)
    {
        $isSupported = function_exists('proc_open');

        if (!$isSupported && $throwException) {
            throw new \RuntimeException(sprintf("proc_open() is required by %s but disabled in PHP",
                    static::class
                )
            );
        }

        return $isSupported;
    }

    /**
     * PosixDriver constructor.
     */
    public function __construct()
    {
        $this->stdout = @fopen('php://stdout', 'w');
        $this->stderr = @fopen('php://stderr', 'w');

        $this->ppid = getmypid();

        parent::__construct();
    }

    public function __destruct()
    {
        @fclose($this->stdout);
        @fclose($this->stderr);
    }

    /**
     * @return PosixProcessBridgeInterface
     */
    private static function getPcntlBridge()
    {
        if (!isset(static::$pcntlBridge)) {
            static::$pcntlBridge = new PcntlBridge();
        }

        return static::$pcntlBridge;
    }

    /**
     * @param PosixProcessBridgeInterface $bridge
     */
    public static function setPcntlBridge(PosixProcessBridgeInterface $bridge)
    {
        static::$pcntlBridge = $bridge;
    }

    /**
     * @param EventManagerInterface $eventManager
     * @return $this
     */
    public function attach(EventManagerInterface $eventManager)
    {
        parent::attach($eventManager);

        $eventManager = $eventManager->getSharedManager();

        $eventManager->attach('*', WorkerEvent::EVENT_WORKER_CREATE, [$this, 'onWorkerCreate'], SchedulerEvent::PRIORITY_FINALIZE + 1);
        $eventManager->attach('*', WorkerEvent::EVENT_WORKER_INIT, [$this, 'onProcessInit'], WorkerEvent::PRIORITY_INITIALIZE + 1);
        $eventManager->attach('*', SchedulerEvent::EVENT_WORKER_TERMINATE, [$this, 'onWorkerTerminate'], -9000);
        $eventManager->attach('*', WorkerEvent::EVENT_WORKER_LOOP, [$this, 'onWorkerLoop'], -9000);
        $eventManager->attach('*', SchedulerEvent::EVENT_SCHEDULER_START, [$this, 'onProcessInit'], -9000);
        $eventManager->attach('*', SchedulerEvent::EVENT_SCHEDULER_STOP, [$this, 'onSchedulerStop'], SchedulerEvent::PRIORITY_FINALIZE);
        $eventManager->attach('*', SchedulerEvent::EVENT_SCHEDULER_LOOP, [$this, 'onSchedulerLoop'], -9000);
        $eventManager->attach('*', SchedulerEvent::EVENT_KERNEL_LOOP, [$this, 'onKernelLoop'], -9000);
        $eventManager->attach('*', ManagerEvent::EVENT_SERVICE_STOP, function() { $this->onServiceStop(); }, -9000);
        $eventManager->attach('*', IpcEvent::EVENT_HANDLING_MESSAGES, function($e) { $this->onIpcSelect($e); }, -9000);
        $eventManager->attach('*', IpcEvent::EVENT_STREAM_READABLE, function($e) { $this->onIpcReadable($e); }, -9000);

        return $this;
    }

    public function onServiceStop()
    {
        while ($this->workers) {
            $this->checkWorkers();
            usleep(10000);
        }
    }

    /**
     * @param EventInterface $event
     */
    public function onWorkerTerminate(EventInterface $event)
    {
        $this->onStopWorker($event->getParam('uid'), $event->getParam('soft', false));
    }

    public function onWorkerLoop(WorkerEvent $event)
    {
        $this->checkPipe();

        if ($this->isTerminating()) {
            $event->onStopWorker(true);
            $event->stopPropagation(true);
        }
    }

    /**
     * @param int $uid
     * @param bool $forceFlush
     * @return $this
     */
    protected function flushBuffers(int $uid, bool $forceFlush)
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

        return $this;
    }

    /**
     * @return $this
     */
    protected function checkWorkers()
    {
        parent::checkWorkers();

        // catch other potential signals to avoid race conditions
        while ($this->workers && ($pid = $this->getPcntlBridge()->pcntlWait($pcntlStatus, WNOHANG|WUNTRACED)) > 0) {
            @fclose($this->workers[$pid]['resource']);

            $this->unregisterWorker($pid);
            $this->raiseWorkerExitedEvent($pid, $pid, 1);
            unset ($this->workers[$pid]);

            $this->flushBuffers($pid, false);
            unset ($this->pipeBuffer[$pid]);
        }

        return $this;
    }

    public function onKernelLoop(SchedulerEvent $event)
    {
        $this->checkWorkers();
    }

    public function onSchedulerLoop(SchedulerEvent $event)
    {
        $wasExiting = $this->isTerminating();

        $this->checkWorkers();
        $this->checkPipe();

        if ($this->isTerminating() && !$wasExiting) {
            $event->stopPropagation();
            $event = $this->getSchedulerEvent();
            $event->setName(SchedulerEvent::EVENT_SCHEDULER_STOP);
            $event->setParam('uid', getmypid());
            $event->setParam('processId', getmypid());
            $event->setParam('threadId', 1);
            $this->events->triggerEvent($event);
        }
    }

    public function onProcessInit(EventInterface $event)
    {
        $this->setConnectionPort($event->getParam('connectionPort'));
    }

    public function onSchedulerStop()
    {
        $this->checkPipe();

        parent::onSchedulerStop();

        $this->checkWorkers();
        foreach ($this->workers as $uid => $worker) {
            $this->stopWorker($uid, true);
        }

        while ($this->workers) {
            $this->checkWorkers();

            if ($this->workers) {
                usleep(1000);
            }
        }
    }

    /**
     * @param SchedulerEvent $event
     * @return int
     */
    public function createProcess(SchedulerEvent $event) : int
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

        $process = proc_open($command, $descriptors, $pipes, getcwd());
        if ($process === false) {
            throw new SchedulerException("Could not create a descendant process", SchedulerException::WORKER_NOT_STARTED);
        }

        $status = proc_get_status($process);
        $pid = $status['pid'];

        $this->workers[$pid] = [
            'resource' => $process,
        ];

        try {
            $this->stdOutStreams[$pid] = new PipeStream($pipes[1]);
            $this->stdOutStreams[$pid]->setBlocking(false);
            $this->stdErrStreams[$pid] = new PipeStream($pipes[2]);
            $this->stdErrStreams[$pid]->setBlocking(false);
        } catch (StreamException $ex) {

        }
        $this->pipeBuffer[$pid] = ['stdout' => '', 'stderr' => ''];

        return $pid;
    }

    public function onWorkerCreate(SchedulerEvent $event)
    {
        $pipe = $this->createPipe();
        $event->setParam('connectionPort', $pipe->getLocalPort());
        $pid = $this->createProcess($event);
        $this->registerWorker($pid, $pipe);
        $event->setParam('uid', $pid);
        $event->setParam('processId', $pid);
        $event->setParam('threadId', 1);
    }

    /**
     * @param int $uid
     * @param bool $useSoftTermination
     * @return $this
     */
    public function onStopWorker(int $uid, bool $useSoftTermination)
    {
        if ($useSoftTermination) {
            parent::onStopWorker($uid, $useSoftTermination);

            return $this;
        } else {
            $this->getPcntlBridge()->posixKill($uid, $useSoftTermination ? SIGINT : SIGKILL);
        }

        if (!isset($this->workers[$uid])) {
            $this->getLogger()->warn("Trying to stop already detached process $uid");

            return $this;
        }

        return $this;
    }

    /**
     * @param int $uid
     * @return $this
     */
    protected function closeProcessPipes(int $uid)
    {
        try {
            if (isset($this->stdErrStreams[$uid])) {
                $this->stdErrStreams[$uid]->close();
            }
        } catch (StreamException $ex) {

        }

        try {
            if (isset($this->stdOutStreams[$uid])) {
                $this->stdOutStreams[$uid]->close();
            }
        } catch (StreamException $ex) {

        }

        unset ($this->stdErrStreams[$uid]);
        unset ($this->stdOutStreams[$uid]);
        $tmpArray = $this->stdErrStreams;
        $this->stdErrStreams = $tmpArray;

        $tmpArray = $this->stdOutStreams;
        $this->stdOutStreams = $tmpArray;
        return $this;
    }

    /**
     * @return MultiProcessingModuleCapabilities
     */
    public function getCapabilities()
    {
        $capabilities = new MultiProcessingModuleCapabilities();
        $capabilities->setIsolationLevel($capabilities::ISOLATION_PROCESS);

        return $capabilities;
    }

    private function onIpcSelect(IpcEvent $event)
    {
        /** @var Selector $selector */
        $selector = $event->getParam('selector');

        foreach ($this->workers as $uid => $worker) {
            $selector->register($this->stdOutStreams[$uid], Selector::OP_READ);
            $selector->register($this->stdErrStreams[$uid], Selector::OP_READ);
        }
    }

    private function onIpcReadable(IpcEvent $event)
    {
        /** @var PipeStream $stream */
        $stream = $event->getParam('stream');

        if (in_array($stream, $this->stdOutStreams)) {
            $outputType = 'stdout';
            $uid = array_search($stream, $this->stdOutStreams);
        } else {
            $outputType = 'stderr';
            $uid = array_search($stream, $this->stdErrStreams);
        }

        try {
            while ($data = $stream->read()) {
                $this->pipeBuffer[$uid][$outputType] .= $data;
            }
        } catch (StreamException $exception) {
            if (!$stream->isReadable()) {
                $this->closeProcessPipes($uid);
            }
        }

        $this->flushBuffers($uid, false);
    }
}