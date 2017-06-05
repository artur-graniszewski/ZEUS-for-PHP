<?php

namespace Zeus\Kernel\ProcessManager\MultiProcessingModule;

use Zend\EventManager\EventInterface;
use Zend\EventManager\EventManagerInterface;
use Zend\Stdlib\ArrayUtils;
use Zeus\Kernel\ProcessManager\MultiProcessingModule\PosixProcess\PcntlBridge;
use Zeus\Kernel\ProcessManager\MultiProcessingModule\PosixProcess\PosixProcessBridgeInterface;
use Zeus\Kernel\ProcessManager\MultiProcessingModule\PosixThread\ThreadWrapper;
use Zeus\Kernel\ProcessManager\SchedulerEvent;

final class PosixThread implements MultiProcessingModuleInterface, SharedAddressSpaceInterface
{
    /** @var EventManagerInterface */
    protected $events;

    /** @var int Parent PID */
    public $ppid;

    /** @var SchedulerEvent */
    protected $processEvent;

    public static $tid = 1;
    public static $em = 1;
    public static $ev = 1;

    protected $threads = [];

    /** @var PosixProcessBridgeInterface */
    protected static $pcntlBridge;

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
        $events->attach(SchedulerEvent::INTERNAL_EVENT_KERNEL_START, [$this, 'onKernelStart']);
        $events->attach(SchedulerEvent::EVENT_PROCESS_CREATE, [$this, 'onProcessCreate']);
        $events->attach(SchedulerEvent::EVENT_PROCESS_WAITING, [$this, 'onProcessWaiting']);
        $events->attach(SchedulerEvent::EVENT_PROCESS_TERMINATE, [$this, 'onProcessTerminate']);
        $events->attach(SchedulerEvent::EVENT_PROCESS_LOOP, [$this, 'onProcessLoop']);
        $events->attach(SchedulerEvent::EVENT_PROCESS_RUNNING, [$this, 'onProcessRunning']);
        $events->attach(SchedulerEvent::EVENT_SCHEDULER_START, [$this, 'onSchedulerInit']);
        $events->attach(SchedulerEvent::EVENT_SCHEDULER_STOP, [$this, 'onSchedulerStop'], -9999);
        $events->attach(SchedulerEvent::EVENT_SCHEDULER_LOOP, [$this, 'onSchedulerLoop']);

        $this->events = $events;

        return $this;
    }

    /**
     * @param bool $throwException
     * @return bool
     * @throws \Exception
     */
    public static function isSupported($throwException = false)
    {
        $supported = extension_loaded("pthreads");

        if (!$throwException) {
            return $supported;
        }

        if (!$supported) {
            throw new \RuntimeException("The pthreads extension is not loaded");
        }
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
        // make the current process a session leader
        $this->getPcntlBridge()->posixSetSid();
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
        $this->getPcntlBridge()->pcntlSignalDispatch();
    }

    public function onProcessWaiting()
    {
        $this->getPcntlBridge()->pcntlSigprocmask(SIG_UNBLOCK, [SIGTERM]);
        $this->onProcessLoop();
    }

    public function onSchedulerLoop()
    {
        // catch other potential signals to avoid race conditions
        while (($pid = $this->getPcntlBridge()->pcntlWait($pcntlStatus, WNOHANG|WUNTRACED)) > 0) {
            $event = new SchedulerEvent();
            $event->setName(SchedulerEvent::EVENT_PROCESS_TERMINATED);
            $event->setParam('uid', $pid);
            $this->events->triggerEvent($event);
        }

        $this->onProcessLoop();

        if ($this->ppid !== $this->getPcntlBridge()->posixGetPpid()) {
            $event = new SchedulerEvent();
            $event->setName(SchedulerEvent::EVENT_SCHEDULER_STOP);
            $event->setParam('uid', $this->ppid);
            $this->events->triggerEvent($event);
        }
    }

    public function onSchedulerStop()
    {
        $this->getPcntlBridge()->pcntlWait($status, WUNTRACED);
        $this->onProcessLoop();
    }

    public function onProcessCreate(SchedulerEvent $event)
    {
        $tid = static::$tid++;
        static::$em = $this->events;
        static::$ev = $event;
//        $thread = ThreadWrapper::call(
//            function () use ($tid, $event) {
//
//                $event->setParam('uid', $tid);
//                $event->setName(SchedulerEvent::EVENT_PROCESS_INIT);
//                $this->events->triggerEvent($event);
//            }
//        );

        $thread = new class extends \Thread {
            public function run() {
                // Setup autoloading
                include '/app/vendor/autoload.php';

                $appConfig = include '/app/config/application.config.php';

                if (file_exists('config/development.config.php')) {
                    $appConfig = ArrayUtils::merge(
                        $appConfig,
                        include '/app/config/development.config.php'
                    );
                }

                $event = PosixThread::$ev;
                $event->setParam('uid', 1);
                $event->setName(SchedulerEvent::EVENT_PROCESS_CREATED);
                PosixThread::$em->triggerEvent($event);
            }
        };

        $thread->start(PTHREADS_INHERIT_NONE|PTHREADS_INHERIT_INI);
        $this->threads[] = $thread;

        $event->setParam('uid', $tid);
        $event->setName(SchedulerEvent::EVENT_PROCESS_CREATED);
        $this->events->triggerEvent($event);
    }

    public function onSchedulerInit(SchedulerEvent $event)
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
    protected function terminateProcess($pid, $useSoftTermination)
    {
        //$this->getPcntlBridge()->posixKill($pid, $useSoftTermination ? SIGINT : SIGKILL);

        return $this;
    }

    /**
     * @return MultiProcessingModuleCapabilities
     */
    public function getCapabilities()
    {
        $capabilities = new MultiProcessingModuleCapabilities();
        $capabilities->setIsolationLevel($capabilities::ISOLATION_THREAD);

        return $capabilities;
    }
}