<?php

namespace Zeus\Kernel\ProcessManager\MultiProcessingModule;

use Zend\EventManager\EventInterface;
use Zend\EventManager\EventManagerInterface;
use Zeus\Kernel\ProcessManager\Exception\ProcessManagerException;
use Zeus\Kernel\ProcessManager\MultiProcessingModule\PosixProcess\PcntlBridge;
use Zeus\Kernel\ProcessManager\MultiProcessingModule\PosixProcess\PosixProcessBridgeInterface;
use Zeus\Kernel\ProcessManager\WorkerEvent;
use Zeus\Kernel\ProcessManager\SchedulerEvent;
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

    protected $stdOut;

    protected $stdErr;

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
        $this->stdOut = @fopen('php://stdout', 'w');
        $this->stdErr = @fopen('php://stderr', 'w');

        $this->ppid = getmypid();

        parent::__construct();
    }

    public function __destruct()
    {
        @fclose($this->stdOut);
        @fclose($this->stdErr);
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
     * @param EventManagerInterface $events
     * @return $this
     */
    public function attach(EventManagerInterface $events)
    {
        parent::attach($events);

        $events = $events->getSharedManager();

        $events->attach('*', SchedulerEvent::EVENT_WORKER_CREATE, [$this, 'onWorkerCreate'], SchedulerEvent::PRIORITY_FINALIZE);
        $events->attach('*', WorkerEvent::EVENT_WORKER_INIT, [$this, 'onProcessInit'], WorkerEvent::PRIORITY_INITIALIZE + 1);
        $events->attach('*', SchedulerEvent::EVENT_WORKER_TERMINATE, [$this, 'onWorkerTerminate'], -9000);
        $events->attach('*', WorkerEvent::EVENT_WORKER_LOOP, [$this, 'onWorkerLoop'], -9000);
        $events->attach('*', SchedulerEvent::EVENT_SCHEDULER_START, [$this, 'onProcessInit'], -9000);
        $events->attach('*', SchedulerEvent::EVENT_SCHEDULER_STOP, [$this, 'onSchedulerStop'], SchedulerEvent::PRIORITY_FINALIZE);
        $events->attach('*', SchedulerEvent::EVENT_SCHEDULER_LOOP, [$this, 'onSchedulerLoop'], -9000);
        $events->attach('*', SchedulerEvent::EVENT_KERNEL_LOOP, [$this, 'onKernelLoop'], -9000);
        $events->attach('*', ManagerEvent::EVENT_SERVICE_STOP, function() { $this->onServiceStop(); }, -9000);

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
        $this->stopWorker($event->getParam('uid'), $event->getParam('soft', false));
    }

    public function onWorkerLoop(WorkerEvent $event)
    {
        $this->checkPipe();

        if ($this->isTerminating()) {
            $event->stopWorker(true);
            $event->stopPropagation(true);
        }
    }

    protected function checkWorkers()
    {
        parent::checkWorkers();
        $this->checkProcessPipes();

        // catch other potential signals to avoid race conditions
        while ($this->workers && ($pid = $this->getPcntlBridge()->pcntlWait($pcntlStatus, WNOHANG|WUNTRACED)) > 0) {
            $this->checkProcessPipes();
            @fclose($this->workers[$pid]['resource']);

            $this->unregisterWorker($pid);
            $this->raiseWorkerExitedEvent($pid, $pid, 1);
            unset ($this->workers[$pid]);
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
            $event = new SchedulerEvent();
            $event->setName(SchedulerEvent::EVENT_SCHEDULER_STOP);
            $event->setParam('uid', getmypid());
            $event->setParam('processId', getmypid());
            $event->setParam('threadId', 1);
            $this->events->triggerEvent($event);
        }
    }

    protected function checkProcessPipes()
    {
        $stdin = $stderr = [];
        foreach ($this->workers as $process) {
            if (isset($process['pipes'][1])) {
                $stdin[] = $process['pipes'][1];
                $stderr[] = $process['pipes'][2];
            }
        }

        $streams = array_merge($stdin, $stderr);
        if ($streams && stream_select($streams, $null, $null, 0)) {
            foreach ($streams as $stream) {
                if (in_array($stream, $stdin)) {
                    $output = $this->stdOut;
                } else {
                    $output = $this->stdErr;
                }

                $outputArray = [$output];
                if (stream_select($null, $outputArray, $null, 0)) {
                    fwrite($output, fread($stream, 16384));
                }
            }

            //$streams = array_merge($stdin, $stderr);
        }

        return $this;
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

    protected function startWorker(SchedulerEvent $event)
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
            throw new ProcessManagerException("Could not create a descendant process", ProcessManagerException::WORKER_NOT_STARTED);
        }

        $status = proc_get_status($process);
        $pid = $status['pid'];

//        stream_set_blocking($pipes[0], false);
//        stream_set_blocking($pipes[1], false);
//        stream_set_blocking($pipes[2], false);
        $this->workers[$pid] = [
            'resource' => $process,
            'pipes' => $pipes
        ];

        return $pid;
    }

    public function onWorkerCreate(SchedulerEvent $event)
    {
        $pipe = $this->createPipe();
        $event->setParam('connectionPort', $pipe->getLocalPort());
        $pid = $this->startWorker($event);
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
    protected function stopWorker(int $uid, bool $useSoftTermination)
    {
        if ($useSoftTermination) {
            parent::stopWorker($uid, $useSoftTermination);

            return $this;
        } else {
            $this->getPcntlBridge()->posixKill($uid, $useSoftTermination ? SIGINT : SIGKILL);
        }

        if (!isset($this->workers[$uid])) {
            $this->getLogger()->warn("Trying to stop already detached process $uid");

            return $this;
        }

        return $this;
        $process = $this->workers[$uid];

        $this->closeProcessPipes($uid);

        proc_terminate($process['resource']);

        $this->workers[$uid] = null;

        return $this;
    }

    protected function closeProcessPipes(int $uid)
    {
        $process = $this->workers[$uid];

        foreach($process['pipes'] as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }

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
}