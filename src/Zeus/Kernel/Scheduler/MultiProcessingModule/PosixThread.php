<?php

namespace Zeus\Kernel\Scheduler\MultiProcessingModule;

use Zeus\Kernel\Scheduler\Helper\GarbageCollector;
use Zeus\Kernel\Scheduler\MultiProcessingModule\PThreads\ThreadBootstrap;
use Zeus\Kernel\Scheduler\WorkerEvent;
use Zeus\Kernel\Scheduler\SchedulerEvent;

final class PosixThread extends AbstractModule implements MultiProcessingModuleInterface, SeparateAddressSpaceInterface
{
    use GarbageCollector;

    const MIN_STABLE_PHP_VERSION = 7.2;

    /** @var int Parent PID */
    public $ppid;

    /** @var \Thread[] */
    protected $workers = [];

    /** @var int */
    protected static $id = 1;

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

    public function onWorkerInit(WorkerEvent $event)
    {
        $this->setIpcAddress('tcp://' . static::LOOPBACK_INTERFACE . ':' . \ZEUS_THREAD_CONN_PORT);

//        if ($this->isTerminating()) {
//            $event->getWorker()->setIsTerminating(true);
//            $event->stopPropagation(true);
//        }

    }

    public function onSchedulerStop(SchedulerEvent $event)
    {
        parent::onSchedulerStop($event);

        while ($this->workers) {
            $this->checkWorkers();
            if ($this->workers) {
                sleep(1);
                $amount = count($this->workers);
                $this->getLogger()->info("Waiting $amount for workers to exit");
            }
        }
    }

    public function onWorkerExit(WorkerEvent $event)
    {
        // @todo: investigate why PHP must have a stderr stream open for each thread, otherwise thread may hang on exit
        file_put_contents("php://stderr", "");
        file_put_contents("php://stdout", "");
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
            }
        }

        return $this;
    }

    private function createThread(WorkerEvent $event)
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
        $worker = $event->getWorker();
        $worker->setThreadId($uid);
        $worker->setProcessId(getmypid());
        $worker->setUid($uid);
    }

    public function onSchedulerInit(SchedulerEvent $event)
    {
        if (version_compare((float) phpversion(), self::MIN_STABLE_PHP_VERSION, "<")) {
            $this->getLogger()->warn(sprintf("Thread safety in PHP %s is broken: pthreads MPM may be unstable!", phpversion(), self::MIN_STABLE_PHP_VERSION));
        }

        parent::onSchedulerInit($event);
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