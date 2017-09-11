<?php

namespace Zeus\Kernel\ProcessManager\MultiProcessingModule;

use Zend\EventManager\EventManagerInterface;
use Zeus\Kernel\ProcessManager\Helper\GarbageCollector;
use Zeus\Kernel\ProcessManager\MultiProcessingModule\PThreads\ThreadBootstrap;
use Zeus\Kernel\ProcessManager\WorkerEvent;
use Zeus\Kernel\ProcessManager\SchedulerEvent;
use Zeus\ServerService\ManagerEvent;

final class PosixThread extends AbstractModule implements MultiProcessingModuleInterface, SeparateAddressSpaceInterface
{
    use GarbageCollector;

    const LOOPBACK_INTERFACE = '127.0.0.1';

    const MIN_STABLE_PHP_VERSION = 7.2;

    /** Timeout in seconds */
    const UPSTREAM_CONNECTION_TIMEOUT = 5;

    /** @var EventManagerInterface */
    protected $events;

    /** @var int Parent PID */
    public $ppid;

    /** @var \Thread[] */
    protected $workers = [];

    /** @var int */
    protected static $id = 0;

    /**
     * @param EventManagerInterface $events
     * @return $this
     */
    public function attach(EventManagerInterface $events)
    {
        parent::attach($events);

        $events = $events->getSharedManager();

        $events->attach('*', SchedulerEvent::EVENT_WORKER_CREATE, function(SchedulerEvent $e) { $this->onWorkerCreate($e); }, SchedulerEvent::PRIORITY_FINALIZE);
        $events->attach('*', SchedulerEvent::EVENT_WORKER_TERMINATE, function(SchedulerEvent $e) { $this->onWorkerStop($e); }, -9000);
        $events->attach('*', SchedulerEvent::EVENT_SCHEDULER_START, function(SchedulerEvent $e) { $this->onSchedulerInit($e); }, -9000);
        $events->attach('*', SchedulerEvent::EVENT_SCHEDULER_STOP, function(SchedulerEvent $e) { $this->onSchedulerStop(); }, WorkerEvent::PRIORITY_FINALIZE);
        $events->attach('*', SchedulerEvent::EVENT_SCHEDULER_LOOP, function(SchedulerEvent $e) { $this->onSchedulerLoop($e); }, -9000);
        $events->attach('*', WorkerEvent::EVENT_WORKER_LOOP, function(WorkerEvent $e) { $this->onWorkerLoop($e); }, WorkerEvent::PRIORITY_INITIALIZE);
        $events->attach('*', WorkerEvent::EVENT_WORKER_INIT, function(WorkerEvent $e) { $this->onWorkerInit($e); }, WorkerEvent::PRIORITY_INITIALIZE + 1);
        $events->attach('*', WorkerEvent::EVENT_WORKER_INIT, function(WorkerEvent $e) { $this->onWorkerLoop($e); }, WorkerEvent::PRIORITY_INITIALIZE + 1);
        $events->attach('*', ManagerEvent::EVENT_SERVICE_STOP, function() { $this->onServiceStop(); }, -9000);

        return $this;
    }

    protected function onWorkerInit()
    {
        $this->setConnectionPort(\ZEUS_THREAD_CONN_PORT);
    }

    protected function onServiceStop()
    {
        $this->stopWorker(1, true);

        while ($this->workers) {
            $this->checkWorkers();
            usleep(100);
        }
    }

    /**
     * @return $this
     */
    protected function checkPipe()
    {
        parent::checkPipe();

        if ($this->isTerminating()) {
            // @todo: investigate why PHP must have a stderr stream open for each thread, otherwise thread may hang on exit
            file_put_contents("php://stderr", "");
            file_put_contents("php://stdout", "");
        }

        return $this;
    }

    /**
     * @return $this
     */
    protected function checkWorkers()
    {
        parent::checkWorkers();

        foreach ($this->workers as $threadId => $thread) {
            if ($thread->isTerminated()) {

                $this->workers[$threadId] = null;
                unset ($this->workers[$threadId]);
                $this->unregisterWorker($threadId);

                $this->raiseWorkerExitedEvent($threadId, 1, $threadId);

                continue;
            }
        }

        return $this;
    }

    protected function onSchedulerLoop(SchedulerEvent $event)
    {
        $wasExiting = $this->isTerminating();

        $this->checkPipe();
        $this->checkWorkers();

        if ($this->isTerminating() && !$wasExiting) {
            $event->stopPropagation();
            $event = new SchedulerEvent();
            $event->setName(SchedulerEvent::EVENT_SCHEDULER_STOP);
            $event->setParam('uid', getmypid());
            $event->setParam('processId', getmypid());
            $event->setParam('threadId', \ZEUS_THREAD_ID);
            $this->events->triggerEvent($event);
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
        parent::onSchedulerStop();

        $this->checkWorkers();
        foreach ($this->workers as $uid => $thread) {
            $this->stopWorker($uid, false);
        }

        while ($this->workers) {
            $this->checkWorkers();

            if ($this->workers) {
                sleep(1);
            }
        }
    }

    /**
     * @param SchedulerEvent $event
     */
    protected function onWorkerStop(SchedulerEvent $event)
    {
        $this->stopWorker($event->getParam('uid'), $event->getParam('soft', false));
    }

    protected function startWorker(SchedulerEvent $event)
    {
        $applicationPath = $_SERVER['PHP_SELF'];

        $type = $event->getParam('server') ? 'scheduler' : 'worker';

        $argv = [
            $applicationPath,
            'zeus',
            $type,
            $event->getTarget()->getConfig()->getServiceName(),
            json_encode($event->getParams())
        ];

        $thread = new ThreadBootstrap();

        static::$id++;

        $port = $this->registerWorker(static::$id);

        $this->collectCycles();
        $thread->server = $_SERVER;
        $thread->argv = $argv;
        $thread->id = static::$id;
        $thread->ipcPort = $port;
        $thread->start(PTHREADS_INHERIT_NONE);

        $this->workers[static::$id] = $thread;

        return static::$id;
    }

    protected function onWorkerCreate(SchedulerEvent $event)
    {
        $pid = $this->startWorker($event);

        $event->setParam('uid', $pid);
        $event->setParam('processId', getmypid());
        $event->setParam('threadId', $pid);
    }

    protected function onSchedulerInit(SchedulerEvent $event)
    {
        if (version_compare((float) phpversion(), self::MIN_STABLE_PHP_VERSION, "<")) {
            $this->getLogger()->warn(sprintf("Thread safety in PHP %s is broken: pthreads MPM may be unstable!", phpversion(), self::MIN_STABLE_PHP_VERSION));
        }

        $this->setConnectionPort(\ZEUS_THREAD_CONN_PORT);
        static::$id = \ZEUS_THREAD_ID;
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