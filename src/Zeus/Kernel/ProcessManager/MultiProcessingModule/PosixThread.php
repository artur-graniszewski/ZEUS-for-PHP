<?php

namespace Zeus\Kernel\ProcessManager\MultiProcessingModule;

use Zend\EventManager\EventInterface;
use Zend\EventManager\EventManagerInterface;
use Zeus\Kernel\ProcessManager\Exception\ProcessManagerException;
use Zeus\Kernel\ProcessManager\MultiProcessingModule\PosixProcess\PcntlBridge;
use Zeus\Kernel\ProcessManager\MultiProcessingModule\PosixProcess\PosixProcessBridgeInterface;
use Zeus\Kernel\ProcessManager\ProcessEvent;
use Zeus\Kernel\ProcessManager\SchedulerEvent;

final class PosixThread implements MultiProcessingModuleInterface, SeparateAddressSpaceInterface
{
    /** @var EventManagerInterface */
    protected $events;

    /** @var int Parent PID */
    public $ppid;

    /** @var SchedulerEvent */
    protected $event;

    /** @var SchedulerEvent */
    protected $processEvent;

    /** @var PosixProcessBridgeInterface */
    protected static $pcntlBridge;

    protected $processes = [];

    protected $threads = [];

    protected static $id = 0;

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

        $events->attach('*', SchedulerEvent::INTERNAL_EVENT_KERNEL_START, [$this, 'onKernelStart'], -9000);
        $events->attach('*', SchedulerEvent::EVENT_PROCESS_CREATE, [$this, 'onProcessCreate'], 1000);
        $events->attach('*', ProcessEvent::EVENT_PROCESS_INIT, [$this, 'onProcessInit'], -9000);
        $events->attach('*', SchedulerEvent::EVENT_PROCESS_WAITING, [$this, 'onProcessWaiting'], -9000);
        $events->attach('*', SchedulerEvent::EVENT_PROCESS_TERMINATE, [$this, 'onProcessTerminate'], -9000);
        $events->attach('*', ProcessEvent::EVENT_PROCESS_LOOP, [$this, 'onProcessLoop'], -9000);
        $events->attach('*', SchedulerEvent::EVENT_PROCESS_RUNNING, [$this, 'onProcessRunning'], -9000);
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
    public function onProcessTerminate(EventInterface $event)
    {
        $this->terminateProcess($event->getParam('uid'), $event->getParam('soft', false));
    }

    /**
     *
     */
    public function onKernelStart()
    {
    }

    public function onSchedulerTerminate()
    {
        $event = new SchedulerEvent();
        $event->setName(SchedulerEvent::EVENT_SCHEDULER_STOP);
        $event->setParam('uid', getmypid());
        $this->events->triggerEvent($event);
    }

    public function onProcessRunning()
    {
        $this->getPcntlBridge()->pcntlSigprocmask(SIG_BLOCK, [SIGTERM]);
    }

    public function onProcessLoop()
    {
        global $terminate;

        trigger_error("TERMINATE ? " .json_encode($terminate));
        $this->getPcntlBridge()->pcntlSignalDispatch();
    }

    public function onProcessWaiting()
    {
        $this->getPcntlBridge()->pcntlSigprocmask(SIG_UNBLOCK, [SIGTERM]);
        $this->onProcessLoop();
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
            $event->setName(SchedulerEvent::EVENT_PROCESS_TERMINATED);
            $event->setParam('uid', $pid);
            $this->events->triggerEvent($event);
        }

        $this->onProcessLoop();
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
        if (@stream_select($streams, $null, $null, 0)) {
            foreach ($streams as $stream) {
                fpassthru($stream);
            }
        }

        return $this;
    }

    public function onProcessInit()
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
        $this->onProcessLoop();
    }

    protected function startProcess(SchedulerEvent $event)
    {
        $applicationPath = $_SERVER['PHP_SELF'];

        $type = $event->getParam('server') ? 'scheduler' : 'process';

        $argv = [$applicationPath, 'zeus', $type, $event->getTarget()->getConfig()->getServiceName()];

        $thread = new class extends \Thread {
            public $server;
            public $argv;
            public $id;
            public $terminate = false;
            public function run() {
                global $_SERVER;
                global $argv;
                global $argc;
                $_SERVER = [];
                foreach ($this->server as $type => $value) {
                    $_SERVER[$type] = $value;
                }
                $_SERVER['argv'] = (array) $this->argv;
                $_SERVER['argc'] = count($this->argv);

                $argv = $_SERVER['argv'];
                $argc = $_SERVER['argc'];

                $terminate = $this;
                $php = '
                    $SERVER = ' . var_export((array) $_SERVER, true) .';
                    foreach ($SERVER as $type => $value) {
                        $_SERVER[$type] = $value;
                    }

                    require_once($_SERVER[\'SCRIPT_NAME\']);
                ?>';

                $this->id = \Thread::getCurrentThreadId();
                eval ($php);
                exit();
            }
        };

        $thread->server = $_SERVER;
        $thread->argv = $argv;
        $thread->start(PTHREADS_INHERIT_INI);

        static::$id++;

        $this->threads[static::$id] = $thread;

        return static::$id;
    }

    public function onProcessCreate(SchedulerEvent $event)
    {
        $pid = $this->startProcess($event);

        $event->setParam('uid', $pid);
    }

    public function onSchedulerInit(SchedulerEvent $event)
    {
        $this->event = new SchedulerEvent();
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
    protected function terminateProcess($pid, $useSoftTermination)
    {
        /** @var \Thread $threadToTerminate */
        $threadToTerminate = $this->threads[$pid];
        $threadToTerminate->synchronized(
            function($thread) {
                trigger_error("TERMINATE!!!");
                $thread->terminate = true;
                $thread->notify();
            }, $threadToTerminate
        );
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