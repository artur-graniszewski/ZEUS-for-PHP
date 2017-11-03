<?php

namespace Zeus\Kernel\Scheduler\MultiProcessingModule;

use Zend\EventManager\EventManagerInterface;
use Zeus\Kernel\Scheduler\Helper\GarbageCollector;
use Zeus\Kernel\Scheduler\MultiProcessingModule\PThreads\ThreadBootstrap;
use Zeus\Kernel\Scheduler\WorkerEvent;
use Zeus\Kernel\Scheduler\SchedulerEvent;

final class PosixThread extends AbstractModule implements MultiProcessingModuleInterface, SeparateAddressSpaceInterface
{
    use GarbageCollector;

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

    /**
     * @param EventManagerInterface $eventManager
     * @return $this
     */
    public function attach(EventManagerInterface $eventManager)
    {
        parent::attach($eventManager);

        //$eventManager->attach(WorkerEvent::EVENT_WORKER_CREATE, function(WorkerEvent $e) { $this->onWorkerCreate($e); }, SchedulerEvent::PRIORITY_FINALIZE + 1);
        //$eventManager->attach(SchedulerEvent::EVENT_WORKER_TERMINATE, function(SchedulerEvent $e) { $this->onWorkerStop($e); }, -9000);
        $eventManager->attach(SchedulerEvent::EVENT_SCHEDULER_START, function(SchedulerEvent $e) { $this->onSchedulerInit($e); }, -9000);
        $eventManager->attach(SchedulerEvent::EVENT_SCHEDULER_STOP, function(SchedulerEvent $e) { $this->onSchedulerStop(); }, SchedulerEvent::PRIORITY_FINALIZE);
        $eventManager->attach(WorkerEvent::EVENT_WORKER_INIT, function(WorkerEvent $e) { $this->onWorkerInit(); }, WorkerEvent::PRIORITY_INITIALIZE + 1);
        $eventManager->attach(WorkerEvent::EVENT_WORKER_INIT, function(WorkerEvent $e) { $this->onWorkerLoop($e); }, WorkerEvent::PRIORITY_INITIALIZE + 1);

        return $this;
    }

    protected function onWorkerInit()
    {
        $this->setConnectionPort(\ZEUS_THREAD_CONN_PORT);
    }

    protected function onServiceStop()
    {
        while ($this->workers) {
            $this->checkWorkers();
            usleep(10000);
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

                $this->raiseWorkerExitedEvent($threadId, getmypid(), $threadId);

                continue;
            }
        }

        return $this;
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
                usleep(10000);
            }
        }
    }

    protected function createThread(WorkerEvent $event)
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

        $pipe = $this->createPipe();
        $this->registerWorker(static::$id, $pipe);

        $this->collectCycles();
        $thread->server = $_SERVER;
        $thread->argv = $argv;
        $thread->id = static::$id;
        $thread->ipcPort = $pipe->getLocalPort();
        $thread->start(PTHREADS_INHERIT_NONE);

        $this->workers[static::$id] = $thread;

        return static::$id;
    }

    public function onWorkerCreate(WorkerEvent $event)
    {
        $uid = $this->createThread($event);

        $event->setParam('uid', $uid);
        $event->setParam('processId', getmypid());
        $event->setParam('threadId', $uid);
        $worker = $event->getWorker();
        $worker->setThreadId($uid);
        $worker->setProcessId(getmypid());
        $worker->setUid($uid);
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