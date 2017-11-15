<?php

namespace Zeus\Kernel\Scheduler\MultiProcessingModule;

use Zend\EventManager\EventInterface;
use Zend\EventManager\EventManagerInterface;
use Zeus\Kernel\IpcServer\IpcEvent;
use Zeus\Kernel\Scheduler\Exception\SchedulerException;
use Zeus\Kernel\Scheduler\MultiProcessingModule\PosixProcess\PcntlBridgeInterface;
use Zeus\Kernel\Scheduler\WorkerEvent;
use Zeus\Kernel\Scheduler\SchedulerEvent;
use Zeus\Networking\Exception\StreamException;
use Zeus\Networking\Stream\PipeStream;
use Zeus\Networking\Stream\Selector;
use Zeus\ServerService\ManagerEvent;

final class ProcessOpen extends AbstractProcessModule implements MultiProcessingModuleInterface, SeparateAddressSpaceInterface
{
    /** @var EventManagerInterface */
    protected $events;

    /** @var PcntlBridgeInterface */
    protected static $pcntlBridge;

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
        $isSupported = function_exists('proc_open') && function_exists('proc_status');

        if (!$isSupported && $throwException) {
            throw new \RuntimeException(sprintf("proc_open() and proc_status() are required by %s but disabled in PHP",
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

        parent::__construct();
    }

    public function __destruct()
    {
        @fclose($this->stdout);
        @fclose($this->stderr);
    }

    /**
     * @param EventManagerInterface $eventManager
     * @return $this
     */
    public function attach(EventManagerInterface $eventManager)
    {
        parent::attach($eventManager);

        $eventManager->attach(WorkerEvent::EVENT_WORKER_INIT, [$this, 'onProcessInit'], WorkerEvent::PRIORITY_INITIALIZE + 1);
        //$eventManager->attach(SchedulerEvent::EVENT_WORKER_TERMINATE, [$this, 'onWorkerTerminate'], -9000);
        //$eventManager->attach(SchedulerEvent::EVENT_SCHEDULER_STOP, [$this, 'onSchedulerStop'], SchedulerEvent::PRIORITY_FINALIZE);
        $eventManager->attach(SchedulerEvent::EVENT_KERNEL_LOOP, [$this, 'onKernelLoop'], -9000);
        $eventManager->getSharedManager()->attach('*', ManagerEvent::EVENT_SERVICE_STOP, function() { $this->onServiceStop(); }, -9000);
        $eventManager->getSharedManager()->attach('*', IpcEvent::EVENT_HANDLING_MESSAGES, function($e) { $this->onIpcSelect($e); }, -9000);
        $eventManager->getSharedManager()->attach('*', IpcEvent::EVENT_STREAM_READABLE, function($e) { $this->checkWorkerOutput($e); }, -9000);

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
    public function checkWorkers()
    {
        parent::checkWorkers();

        if (!$this->getPcntlBridge()->isSupported()) {
            foreach ($this->workers as $pid => $worker) {
                $status = proc_get_status($worker['resource']);

                if (!$status['running']) {
                    $this->processExited($pid);
                }
            }
        }

        return $this;
    }

    private function processExited($pid)
    {
        // check stdOut and stdErr...
        foreach (['stdout' => $this->stdOutStreams[$pid], 'stderr' => $this->stdErrStreams[$pid]] as $name => $stream) {
            try {
                if ($stream->select(0)) {
                    $this->pipeBuffer[$pid][$name] .= $stream->read();
                }
            } catch (StreamException $e) {

            }
        }

        $this->flushBuffers($pid, false);
        unset ($this->pipeBuffer[$pid]);
        @fclose($this->workers[$pid]['resource']);
        $this->raiseWorkerExitedEvent($pid, $pid, 1);
        unset ($this->workers[$pid]);
    }

    public function onKernelLoop(SchedulerEvent $event)
    {
        $this->checkWorkers();
    }

    public function onSchedulerLoop(SchedulerEvent $event)
    {
        $wasExiting = $this->isTerminating();

        $this->checkPipe();
        $this->checkWorkers();

        if ($this->isTerminating() && !$wasExiting) {
            $event->getScheduler()->setIsTerminating(true);
        }
    }

    public function onProcessInit(EventInterface $event)
    {
        $this->setConnectionPort($event->getParam('connectionPort'));
    }

//    public function onSchedulerStop()
//    {
//        $this->checkPipe();
//
//        parent::onSchedulerStop();
//
//        $this->checkWorkers();
//        foreach ($this->workers as $uid => $worker) {
//            $this->stopWorker($uid, true);
//        }
//
//        while ($this->workers) {
//            $this->checkWorkers();
//
//            if ($this->workers) {
//                usleep(1000);
//            }
//        }
//    }

    /**
     * @param WorkerEvent $event
     * @return int
     */
    public function createProcess(WorkerEvent $event) : int
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

    public function onWorkerCreate(WorkerEvent $event)
    {
        $pipe = $this->createPipe();
        $event->setParam('connectionPort', $pipe->getLocalPort());
        $pid = $this->createProcess($event);
        $this->registerWorker($pid, $pipe);
        $event->setParam('uid', $pid);
        $event->setParam('processId', $pid);
        $event->setParam('threadId', 1);
        $worker = $event->getWorker();
        $worker->setProcessId($pid);
        $worker->setUid($pid);
        $worker->setThreadId(1);
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

    private function checkWorkerOutput(IpcEvent $event)
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
        }

        $this->flushBuffers($uid, false);
    }
}