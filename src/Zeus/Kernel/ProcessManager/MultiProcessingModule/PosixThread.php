<?php

namespace Zeus\Kernel\ProcessManager\MultiProcessingModule;

use Zend\EventManager\EventInterface;
use Zend\EventManager\EventManagerInterface;


use Zeus\Kernel\Networking\SocketServer;
use Zeus\Kernel\ProcessManager\TaskEvent;
use Zeus\Kernel\ProcessManager\Scheduler;
use Zeus\Kernel\ProcessManager\SchedulerEvent;
use Zeus\ServerService\ManagerEvent;

final class PosixThread implements MultiProcessingModuleInterface, SeparateAddressSpaceInterface
{
    const LOOPBACK_INTERFACE = '127.0.0.1';

    /** @var EventManagerInterface */
    protected $events;

    /** @var int Parent PID */
    public $ppid;

    /** @var SchedulerEvent */
    protected $event;

    /** @var SchedulerEvent */
    protected $processEvent;

    protected $processes = [];

    /** @var \Thread[] */
    protected $threads = [];

    /** @var SocketServer[] */
    protected $threadIpcs = [];

    protected static $id = 0;

    /** @var Scheduler */
    protected $scheduler;

    /** @var SocketServer */
    protected $ipc;

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
        $events->attach('*', SchedulerEvent::EVENT_SCHEDULER_STOP, [$this, 'onSchedulerStop'], -9000);
        $events->attach('*', SchedulerEvent::EVENT_SCHEDULER_LOOP, [$this, 'onSchedulerLoop'], -9000);
        $events->attach('*', TaskEvent::EVENT_PROCESS_LOOP, [$this, 'onProcessLoop'], -9000);
        $events->attach('*', SchedulerEvent::EVENT_KERNEL_LOOP, [$this, 'onKernelLoop'], -9000);
        $events->attach('*', ManagerEvent::EVENT_SERVICE_STOP, [$this, 'onManagerStop'], -9000);

        return $this;
    }

    public function onManagerStop()
    {
        $this->terminateProcess(1, true);
    }

    protected function isPipeBroken()
    {
        $stream = @stream_socket_client('tcp://' . static::LOOPBACK_INTERFACE . ':' . ZEUS_THREAD_CONN_PORT, $errno, $errstr, 1);

        if ($stream) {
            fclose($stream);
        }
        return ($stream === false);
    }

    public function onProcessLoop(TaskEvent $event)
    {
        if ($this->isPipeBroken()) {
            $event->setParam('exit', true);
            return;
        }
    }

    public function onKernelLoop()
    {

    }

    public function onSchedulerLoop(SchedulerEvent $event)
    {
        if ($this->isPipeBroken()) {
            $event = new SchedulerEvent();
            $event->setName(SchedulerEvent::EVENT_SCHEDULER_STOP);
            $event->setParam('uid', $this->ppid);
            $this->events->triggerEvent($event);
            return;
        }

        foreach ($this->threads as $threadId => $thread) {
            $this->threadIpcs[$threadId]->accept(0);
            if ($thread->isTerminated()) {
                $this->threads[$threadId] = null;

                $newEvent = new SchedulerEvent();
                $newEvent->setParam('uid', $threadId);
                $newEvent->setParam('threadId', $threadId);
                $event->setParam('processId', getmypid());
                $newEvent->setName(SchedulerEvent::EVENT_PROCESS_TERMINATED);
                $this->events->triggerEvent($newEvent);
                unset ($this->threads[$threadId]);
                unset($this->threadIpcs[$threadId]);
            }
        }
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
        foreach ($this->threads as $key => $thread) {
            $this->threads[$key] = null;
        }
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
        $event->setParam('processId', getmypid());
        $this->events->triggerEvent($event);
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
            /** @var int */
            public $ipcPort;

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
                    define("ZEUS_THREAD_CONN_PORT", ' . $this->ipcPort . ');
                    define("ZEUS_THREAD_ID", ' . $this->id . ');
                    $SERVER = ' . var_export((array) $_SERVER, true) .';
                    foreach ($SERVER as $type => $value) {
                        $_SERVER[$type] = $value;
                    }

                    unset ($SERVER);
                    require_once($_SERVER[\'SCRIPT_NAME\']);
                ?>';

                $this->id = \Thread::getCurrentThreadId();

                eval ($php);
                exit();
            }
        };

        static::$id++;
        $this->threadIpcs[static::$id] = new SocketServer(0, 5, static::LOOPBACK_INTERFACE);
        $port = $this->threadIpcs[static::$id]->getLocalPort();

        $stream = @stream_socket_client('tcp://' . static::LOOPBACK_INTERFACE . ':' . $port, $errno, $errstr, 1);
        if ($stream === false) {
            $event->setParam('exit', true);
            return;
        }

        $thread->server = $_SERVER;
        $thread->argv = $argv;
        $thread->id = static::$id;
        $thread->ipcPort = $port;
        $thread->start(PTHREADS_INHERIT_INI);

        $this->threads[static::$id] = $thread;

        return static::$id;
    }

    public function onProcessCreate(SchedulerEvent $event)
    {
        $pid = $this->startProcess($event);

        $event->setParam('uid', $pid);
        $event->setParam('processId', getmypid());
        $event->setParam('threadId', $pid);
    }

    public function onSchedulerInit(SchedulerEvent $event)
    {
        static::$id = ZEUS_THREAD_ID;
    }

    /**
     * @param int $pid
     * @param bool $useSoftTermination
     * @return $this
     */
    protected function terminateProcess($pid, $useSoftTermination)
    {
        if (!isset($this->threads[$pid]) || !$this->threads[$pid]) {
            return $this;
        }

        if ($this->threadIpcs[$pid]->isBound()) {
            $this->threadIpcs[$pid]->close();
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