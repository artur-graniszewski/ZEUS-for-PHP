<?php

namespace Zeus\Kernel\Scheduler\MultiProcessingModule;

use Zeus\Kernel\Scheduler\Helper\GarbageCollector;
use Zeus\Kernel\Scheduler\MultiProcessingModule\PThreads\ThreadBootstrap;
use Zeus\Kernel\Scheduler\WorkerEvent;
use Zeus\Kernel\Scheduler\SchedulerEvent;

final class PosixThread extends AbstractModule implements SeparateAddressSpaceInterface
{
    use GarbageCollector;

    const MIN_STABLE_PHP_VERSION = 7.2;

    /** @var \Thread[] */
    protected $workers = [];

    /** @var int */
    protected static $id = 1;

    public static function isSupported(& $errorMessage = '') : bool
    {
        $isSupported = class_exists(\Thread::class);

        if (!$isSupported) {
            $className = basename(str_replace('\\', '/', static::class));

            $errorMessage = sprintf("pThreads extension is required by %s but disabled in PHP",
                $className);
        }

        return $isSupported;
    }

    public function onWorkerInit(WorkerEvent $event)
    {
        $this->getWrapper()->setIpcAddress('tcp://' . \ZEUS_THREAD_CONN_PORT);
    }

    public function onSchedulerStop(SchedulerEvent $event)
    {
        while ($this->workers) {
            $this->onWorkersCheck($event);
            if ($this->workers) {
                sleep(1);
                $amount = count($this->workers);
                $this->getWrapper()->getLogger()->info("Waiting for $amount workers to exit");
            }
        }
    }

    public function onWorkerExit(WorkerEvent $event)
    {
        // @todo: investigate why PHP must have a stderr stream open for each thread, otherwise thread may hang on exit
        file_put_contents("php://stderr", "");
        file_put_contents("php://stdout", "");
    }

    public function onWorkersCheck(SchedulerEvent $event)
    {
        foreach ($this->workers as $threadId => $thread) {
            if ($thread->isTerminated()) {
                $this->workers[$threadId] = null;
                unset ($this->workers[$threadId]);

                $this->getWrapper()->raiseWorkerExitedEvent($threadId, getmypid(), $threadId);
            }
        }
    }

    private function createThread(WorkerEvent $event) : int
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

        $this->collectCycles();
        $thread->server = $_SERVER;
        $thread->argv = $argv;
        $thread->id = static::$id;
        $thread->ipcPort = $event->getParam(ModuleWrapper::ZEUS_IPC_ADDRESS_PARAM);
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
            $this->getWrapper()->getLogger()->warn(sprintf("Thread safety in PHP %s is broken: pthreads MPM may be unstable!", phpversion(), self::MIN_STABLE_PHP_VERSION));
        }
    }

    public static function getCapabilities() : MultiProcessingModuleCapabilities
    {
        $capabilities = new MultiProcessingModuleCapabilities();
        $capabilities->setIsolationLevel($capabilities::ISOLATION_THREAD);

        return $capabilities;
    }

    public function onKernelStart(SchedulerEvent $event)
    {
        // TODO: Implement onKernelStart() method.
    }

    public function onKernelLoop(SchedulerEvent $event)
    {
        // TODO: Implement onKernelLoop() method.
    }

    public function onWorkerTerminate(WorkerEvent $event)
    {
        // TODO: Implement onWorkerTerminate() method.
    }

    public function onWorkerTerminated(WorkerEvent $event)
    {
        // TODO: Implement onWorkerTerminated() method.
    }

    public function onSchedulerLoop(SchedulerEvent $event)
    {
        // TODO: Implement onSchedulerLoop() method.
    }

    public function onWorkerLoop(WorkerEvent $event)
    {
        // TODO: Implement onWorkerLoop() method.
    }
}