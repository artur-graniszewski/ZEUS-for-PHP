<?php

namespace Zeus\Kernel\ProcessManager\MultiProcessingModule;

use Zend\EventManager\EventInterface;
use Zend\EventManager\EventManagerInterface;
use Zeus\Kernel\ProcessManager\Exception\ProcessManagerException;
use Zeus\Kernel\ProcessManager\MultiProcessingModule\PosixProcess\PcntlBridge;
use Zeus\Kernel\ProcessManager\MultiProcessingModule\PosixProcess\PosixProcessBridgeInterface;
use Zeus\Kernel\ProcessManager\WorkerEvent;
use Zeus\Kernel\ProcessManager\SchedulerEvent;

final class ProcessOpen extends AbstractModule implements MultiProcessingModuleInterface, SeparateAddressSpaceInterface
{
    /** @var EventManagerInterface */
    protected $events;

    /** @var int Parent PID */
    public $ppid;

    /** @var PosixProcessBridgeInterface */
    protected static $pcntlBridge;

    protected $processes = [];

    /**
     * PosixDriver constructor.
     */
    public function __construct()
    {
        $this->ppid = getmypid();
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
        $this->events = $events;
        $events = $events->getSharedManager();
        $events->attach('*', SchedulerEvent::EVENT_WORKER_CREATE, [$this, 'onWorkerCreate'], SchedulerEvent::PRIORITY_FINALIZE);
        $events->attach('*', WorkerEvent::EVENT_WORKER_INIT, [$this, 'onWorkerInit'], -9000);
        $events->attach('*', WorkerEvent::EVENT_WORKER_WAITING, [$this, 'onWorkerWaiting'], -9000);
        $events->attach('*', SchedulerEvent::EVENT_WORKER_TERMINATE, [$this, 'onWorkerTerminate'], -9000);
        $events->attach('*', WorkerEvent::EVENT_WORKER_LOOP, [$this, 'onWorkerLoop'], -9000);
        $events->attach('*', WorkerEvent::EVENT_WORKER_RUNNING, [$this, 'onWorkerRunning'], -9000);
        $events->attach('*', SchedulerEvent::EVENT_SCHEDULER_START, [$this, 'onSchedulerInit'], -9000);
        $events->attach('*', SchedulerEvent::EVENT_SCHEDULER_STOP, [$this, 'onSchedulerStop'], -9000);
        $events->attach('*', SchedulerEvent::EVENT_SCHEDULER_LOOP, [$this, 'onSchedulerLoop'], -9000);
        $events->attach('*', SchedulerEvent::EVENT_KERNEL_LOOP, [$this, 'onKernelLoop'], -9000);

        return $this;
    }

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
     * @param EventInterface $event
     */
    public function onWorkerTerminate(EventInterface $event)
    {
        $this->stopWorker($event->getParam('uid'), $event->getParam('soft', false));
    }


    public function onSchedulerTerminate()
    {
        $event = new SchedulerEvent();
        $event->setName(SchedulerEvent::EVENT_SCHEDULER_STOP);
        $event->setParam('uid', getmypid());
        $event->setParam('processId', getmypid());
        $this->events->triggerEvent($event);
    }

    public function onWorkerRunning()
    {
        $this->getPcntlBridge()->pcntlSigprocmask(SIG_BLOCK, [SIGTERM]);
    }

    public function onWorkerLoop()
    {
        $this->getPcntlBridge()->pcntlSignalDispatch();
    }

    public function onWorkerWaiting()
    {
        $this->getPcntlBridge()->pcntlSigprocmask(SIG_UNBLOCK, [SIGTERM]);
        $this->onWorkerLoop();
    }

    public function onKernelLoop()
    {
        $this->readPipes();
    }

    public function onSchedulerLoop(SchedulerEvent $event)
    {
        $this->readPipes();
        // catch other potential signals to avoid race conditions
        while (($pid = $this->getPcntlBridge()->pcntlWait($pcntlStatus, WNOHANG|WUNTRACED)) > 0) {
            $event->setName(SchedulerEvent::EVENT_WORKER_TERMINATED);
            $event->setParam('uid', $pid);
            $this->events->triggerEvent($event);
        }

        $this->onWorkerLoop();
    }

    protected function readPipes()
    {
        $stdin = $stderr = [];
        foreach ($this->processes as $process) {
            if (isset($process['pipes'][1])) {
                $stdin[] = $process['pipes'][1];
                $stderr[] = $process['pipes'][2];
            }
        }

        $streams = array_merge($stdin, $stderr);
        if ($streams && stream_select($streams, $null, $null, 0)) {
            foreach ($streams as $stream) {
                fpassthru($stream);
            }
        }

        return $this;
    }

    public function onWorkerInit()
    {
        $pcntl = $this->getPcntlBridge();
        // we are the new process
        $pcntl->pcntlSignal(SIGTERM, SIG_DFL);
        $pcntl->pcntlSignal(SIGINT, SIG_DFL);
        $pcntl->pcntlSignal(SIGHUP, SIG_DFL);
        $pcntl->pcntlSignal(SIGQUIT, SIG_DFL);
        $pcntl->pcntlSignal(SIGTSTP, SIG_DFL);
    }

    public function onSchedulerStop()
    {
        $this->getPcntlBridge()->pcntlWait($status, WUNTRACED);
        $this->onWorkerLoop();
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

        stream_set_blocking($pipes[0], false);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $this->processes[$pid] = [
            'resource' => $process,
            'pipes' => $pipes
        ];

        return $pid;
    }

    public function onWorkerCreate(SchedulerEvent $event)
    {
        $pid = $this->startWorker($event);
        $event->setParam('uid', $pid);
        $event->setParam('processId', $pid);
        $event->setParam('threadId', 1);
    }

    public function onSchedulerInit()
    {
        $pcntl = $this->getPcntlBridge();
        $onTaskTerminate = function() { $this->onSchedulerTerminate(); };
        //pcntl_sigprocmask(SIG_BLOCK, [SIGCHLD]);
        $pcntl->pcntlSignal(SIGTERM, $onTaskTerminate);
        $pcntl->pcntlSignal(SIGQUIT, $onTaskTerminate);
        $pcntl->pcntlSignal(SIGTSTP, $onTaskTerminate);
        $pcntl->pcntlSignal(SIGINT, $onTaskTerminate);
        $pcntl->pcntlSignal(SIGHUP, $onTaskTerminate);
    }

    /**
     * @param int $pid
     * @param bool $useSoftTermination
     * @return $this
     */
    protected function stopWorker($pid, $useSoftTermination)
    {
        $this->getPcntlBridge()->posixKill($pid, $useSoftTermination ? SIGINT : SIGKILL);

        $process = $this->processes[$pid];

        // @todo: This should NOT be necessary!
        if (!$process) {
            return $this;
        }

        foreach($process['pipes'] as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }

        proc_terminate($process['resource']);

        $this->processes[$pid] = null;

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