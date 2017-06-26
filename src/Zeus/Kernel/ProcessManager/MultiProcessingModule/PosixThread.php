<?php

namespace Zeus\Kernel\ProcessManager\MultiProcessingModule;

use Zend\EventManager\EventInterface;
use Zend\EventManager\EventManagerInterface;
use Zeus\Kernel\ProcessManager\Exception\ProcessManagerException;
use Zeus\Kernel\ProcessManager\MultiProcessingModule\PosixProcess\PcntlBridge;
use Zeus\Kernel\ProcessManager\MultiProcessingModule\PosixProcess\PosixProcessBridgeInterface;
use Zeus\Kernel\ProcessManager\ProcessEvent;
use Zeus\Kernel\ProcessManager\Scheduler;
use Zeus\Kernel\ProcessManager\SchedulerEvent;
use Zeus\ServerService\ManagerEvent;

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

    protected $processes = [];

    protected $threads = [];

    protected static $id = 0;

    /** @var Scheduler */
    protected $scheduler;

    /**
     * PosixDriver constructor.
     */
    public function __construct()
    {
        $this->ppid = getmypid();
    }

    /**
     * @param EventManagerInterface $events
     * @return $this
     */
    public function attach(EventManagerInterface $events)
    {
        $this->events = $events;

        $events = $events->getSharedManager();

        $events->attach('*', SchedulerEvent::EVENT_PROCESS_CREATE, [$this, 'onProcessCreate'], 1000);
        $events->attach('*', SchedulerEvent::EVENT_PROCESS_TERMINATE, [$this, 'onProcessTerminate'], -9000);
        $events->attach('*', SchedulerEvent::EVENT_SCHEDULER_START, [$this, 'onSchedulerInit'], -9000);
        $events->attach('*', SchedulerEvent::EVENT_SCHEDULER_STOP, [$this, 'onSchedulerStop'], 10000);
        $events->attach('*', SchedulerEvent::EVENT_SCHEDULER_LOOP, [$this, 'onSchedulerLoop'], -9000);
        $events->attach('*', SchedulerEvent::EVENT_KERNEL_LOOP, [$this, 'onKernelLoop'], -9000);
        $events->attach('*', ManagerEvent::EVENT_SERVICE_STOP, [$this, 'onManagerStop'], -9000);

        return $this;
    }

    public function onManagerStop()
    {
        $this->terminateProcess(1, true);
        trigger_error("MANAGER STOP");
    }

    /**
     * @param bool $throwException
     * @return bool
     * @throws \Exception
     */
    public static function isSupported($throwException = false)
    {
        $isSupported = class_exists(\Thread::class);

        if (!$isSupported && $throwException) {
            throw new \RuntimeException(sprintf("pthreads extension is required by %s but disabled in PHP",
                    static::class
                )
            );
        }

        return $isSupported;
    }

    public function onSchedulerStop()
    {

    }

    /**
     * @param EventInterface $event
     */
    public function onProcessTerminate(EventInterface $event)
    {
        $this->terminateProcess($event->getParam('uid'), $event->getParam('soft', false));
    }

    public function onSchedulerTerminate()
    {
        $event = new SchedulerEvent();
        $event->setName(SchedulerEvent::EVENT_SCHEDULER_STOP);
        $event->setParam('uid', getmypid());
        $this->events->triggerEvent($event);
    }

    public function onKernelLoop()
    {
        trigger_error(\Thread::getCurrentThreadId() . " KERNEL LOOP");
    }

    public function onSchedulerLoop(SchedulerEvent $event)
    {
    }

    protected function startProcess(SchedulerEvent $event)
    {
        $applicationPath = $_SERVER['PHP_SELF'];

        $type = $event->getParam('server') ? 'scheduler' : 'process';

        $argv = [$applicationPath, 'zeus', $type, $event->getTarget()->getConfig()->getServiceName()];

        $thread = new class extends \Worker {
            public $server;
            public $argv;
            public $id;
            public $terminate = false;

            public function run()
            {
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
    }

    /**
     * @param int $pid
     * @param bool $useSoftTermination
     * @return $this
     */
    protected function terminateProcess($pid, $useSoftTermination)
    {
        if (!isset($this->threads[$pid]) || !$this->threads[$pid]) {
            return;
        }
        trigger_error(\Thread::getCurrentThreadId() . " TRYING TO TERMINATE $pid");

        //$this->threads[$pid]->shutdown();
        trigger_error(\Thread::getCurrentThreadId() . " TERMINATED $pid");
        /** @var \Thread $threadToTerminate */
//        $threadToTerminate = $this->threads[$pid];
//        $threadToTerminate->synchronized(
//            function($thread) {
//                $thread->terminate = true;
//                $thread->notify();
//            }, $threadToTerminate
//        );
        $this->threads[$pid] = null;

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