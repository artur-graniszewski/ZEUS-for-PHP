<?php

namespace Zeus\Kernel\ProcessManager\MultiProcessingModule;

use Zend\EventManager\EventManagerInterface;
use Zeus\Networking\Exception\SocketTimeoutException;
use Zeus\Networking\SocketServer;
use Zeus\Kernel\ProcessManager\WorkerEvent;
use Zeus\Kernel\ProcessManager\SchedulerEvent;
use Zeus\ServerService\ManagerEvent;

final class PosixThread implements MultiProcessingModuleInterface, SeparateAddressSpaceInterface
{
    const LOOPBACK_INTERFACE = '127.0.0.1';

    /** @var EventManagerInterface */
    protected $events;

    /** @var int Parent PID */
    public $ppid;

    /** @var \Thread[] */
    protected $threads = [];

    /** @var SocketServer[] */
    protected $threadIpcs = [];

    /** @var int */
    protected static $id = 0;

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

        $events->attach('*', SchedulerEvent::EVENT_WORKER_CREATE, function(SchedulerEvent $e) { $this->onWorkerCreate($e); }, 1000);
        $events->attach('*', SchedulerEvent::EVENT_WORKER_TERMINATE, function(SchedulerEvent $e) { $this->onWorkerStop($e); }, -9000);
        $events->attach('*', SchedulerEvent::EVENT_SCHEDULER_START, function(SchedulerEvent $e) { $this->onSchedulerInit($e); }, -9000);
        $events->attach('*', SchedulerEvent::EVENT_SCHEDULER_STOP, function(SchedulerEvent $e) { $this->onSchedulerStop(); }, -9000);
        $events->attach('*', SchedulerEvent::EVENT_SCHEDULER_LOOP, function(SchedulerEvent $e) { $this->onSchedulerLoop($e); }, -9000);
        $events->attach('*', WorkerEvent::EVENT_WORKER_LOOP, function(WorkerEvent $e) { $this->onWorkerLoop($e); }, -9000);
        $events->attach('*', ManagerEvent::EVENT_SERVICE_STOP, function() { $this->onManagerStop(); }, -9000);

        return $this;
    }

    protected function onManagerStop()
    {
        $this->stopWorker(1, true);
    }

    protected function isPipeBroken()
    {
        $stream = @stream_socket_client('tcp://' . static::LOOPBACK_INTERFACE . ':' . ZEUS_THREAD_CONN_PORT, $errno, $errstr, 1);

        if ($stream) {
            fclose($stream);
        }
        return ($stream === false);
    }

    protected function onWorkerLoop(WorkerEvent $event)
    {
        if ($this->isPipeBroken()) {
            $event->stopWorker(true);
            return;
        }
    }

    protected function onSchedulerLoop(SchedulerEvent $event)
    {
        if ($this->isPipeBroken()) {
            $event = new SchedulerEvent();
            $event->setName(SchedulerEvent::EVENT_SCHEDULER_STOP);
            $event->setParam('uid', $this->ppid);
            $event->setParam('processId', $this->ppid);
            $this->events->triggerEvent($event);
            return;
        }

        foreach ($this->threads as $threadId => $thread) {
            try {
                $this->threadIpcs[$threadId]->accept();
            } catch (SocketTimeoutException $exception) {
                // @todo: verify if nothing to do?
            }
            if ($thread->isTerminated()) {
                $this->threads[$threadId] = null;

                $newEvent = new SchedulerEvent();
                $newEvent->setParam('uid', $threadId);
                $newEvent->setParam('threadId', $threadId);
                $event->setParam('processId', getmypid());
                $newEvent->setName(SchedulerEvent::EVENT_WORKER_TERMINATED);
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

    protected function onSchedulerStop()
    {
        foreach ($this->threads as $key => $thread) {
            $this->threads[$key] = null;
        }
    }

    /**
     * @param SchedulerEvent $event
     */
    protected function onWorkerStop(SchedulerEvent $event)
    {
        $this->stopWorker($event->getParam('uid'), $event->getParam('soft', false));
    }

    protected function startProcess(SchedulerEvent $event)
    {
        $applicationPath = $_SERVER['PHP_SELF'];

        $type = $event->getParam('server') ? 'scheduler' : 'worker';

        $argv = [$applicationPath, 'zeus', $type, $event->getTarget()->getConfig()->getServiceName()];

        $thread = new class extends \Worker {
            public $server;
            public $argv;
            public $id;
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
        $socketServer = new SocketServer(0, 5, static::LOOPBACK_INTERFACE);
        $socketServer->setSoTimeout(1000);
        $this->threadIpcs[static::$id] = $socketServer;
        $port = $socketServer->getLocalPort();

        $stream = @stream_socket_client('tcp://' . static::LOOPBACK_INTERFACE . ':' . $port, $errno, $errstr, 1);
        if ($stream === false) {

            throw new \RuntimeException("Could not bind new thread to the Scheduler pipe");
        }
        fclose($stream);

        $thread->server = $_SERVER;
        $thread->argv = $argv;
        $thread->id = static::$id;
        $thread->ipcPort = $port;
        $thread->start(PTHREADS_INHERIT_INI);

        $this->threads[static::$id] = $thread;

        return static::$id;
    }

    protected function onWorkerCreate(SchedulerEvent $event)
    {
        $pid = $this->startProcess($event);

        $event->setParam('uid', $pid);
        $event->setParam('processId', getmypid());
        $event->setParam('threadId', $pid);
    }

    protected function onSchedulerInit(SchedulerEvent $event)
    {
        static::$id = ZEUS_THREAD_ID;
    }

    /**
     * @param int $pid
     * @param bool $useSoftTermination
     * @return $this
     */
    protected function stopWorker(int $pid, bool $useSoftTermination)
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
    public function getCapabilities() : MultiProcessingModuleCapabilities
    {
        $capabilities = new MultiProcessingModuleCapabilities();
        $capabilities->setIsolationLevel($capabilities::ISOLATION_THREAD);

        return $capabilities;
    }
}