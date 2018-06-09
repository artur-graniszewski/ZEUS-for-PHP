<?php

namespace Zeus\Kernel\Scheduler\MultiProcessingModule;

use Zeus\Kernel\Scheduler\MultiProcessingModule\PosixProcess\PcntlBridge;
use Zeus\Kernel\Scheduler\MultiProcessingModule\PosixProcess\PcntlBridgeInterface;
use Zeus\Kernel\Scheduler\MultiProcessingModule\PThreads\PosixThreadBridge;
use Zeus\Kernel\Scheduler\MultiProcessingModule\PThreads\PosixThreadBridgeInterface;
use Zeus\Kernel\Scheduler\MultiProcessingModule\PThreads\ThreadWrapper;
use Zeus\Kernel\Scheduler\WorkerEvent;
use Zeus\Kernel\Scheduler\SchedulerEvent;
use Zeus\Kernel\System\Runtime;

use function basename;
use function sprintf;
use function str_replace;
use function file_put_contents;
use function json_encode;
use function version_compare;
use function phpversion;
use function sleep;
use function count;

final class PosixThread extends AbstractModule implements SeparateAddressSpaceInterface
{
    const PTHREADS_INHERIT_NONE = 0;

    const MIN_STABLE_PHP_VERSION = 7.2;

    /** @var PcntlBridgeInterface */
    private static $pcntlBridge;

    /** @var PosixThreadBridgeInterface */
    private static $posixThreadBridge;

    /** @var ThreadWrapper[] */
    private $workers = [];

    /** @var int */
    private static $id = 1;

    protected static function getPosixThreadBridge() : PosixThreadBridgeInterface
    {
        if (!isset(static::$posixThreadBridge)) {
            static::$posixThreadBridge = new PosixThreadBridge();
        }

        return static::$posixThreadBridge;
    }

    protected static function getPcntlBridge() : PcntlBridgeInterface
    {
        if (!isset(static::$pcntlBridge)) {
            static::$pcntlBridge = new PcntlBridge();
        }

        return static::$pcntlBridge;
    }

    public static function setPcntlBridge(PcntlBridgeInterface $bridge)
    {
        static::$pcntlBridge = $bridge;
    }

    public static function setPosixThreadBridge(PosixThreadBridgeInterface $bridge)
    {
        static::$posixThreadBridge = $bridge;
    }

    public static function isSupported(& $errorMessage  = '') : bool
    {
        $bridge = static::getPosixThreadBridge();

        if (!$bridge->isSupported()) {
            $className = basename(str_replace('\\', '/', static::class));

            $errorMessage = sprintf("pThread extension is required by %s but disabled in PHP", $className);

            return false;
        }

        return true;
    }

    public function onWorkerInit(WorkerEvent $event)
    {
        $this->getDecorator()->setIpcAddress(\ZEUS_THREAD_IPC_ADDRESS);
    }

    public function onSchedulerStop(SchedulerEvent $event)
    {
        while ($this->workers) {
            $this->onWorkersCheck($event);
            if ($this->workers) {
                sleep(1);
                $amount = count($this->workers);
                $this->getDecorator()->getLogger()->info("Waiting for $amount workers to exit");
            }
        }
    }

    public function onKernelLoop(SchedulerEvent $event)
    {
        if (static::getPcntlBridge()->isSupported()) {
            static::getPcntlBridge()->pcntlSignalDispatch();
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

                $this->getDecorator()->raiseWorkerExitedEvent($threadId, getmypid(), $threadId);
            }
        }
    }

    private function createThread(WorkerEvent $event) : int
    {
        $applicationPath = $_SERVER['SCRIPT_NAME'] ? $_SERVER['SCRIPT_NAME'] : $_SERVER['PHP_SELF'];

        $type = $event->getParam('server') ? 'scheduler' : 'worker';

        $argv = [
            $applicationPath,
            'zeus',
            $type,
            $event->getWorker()->getServiceName(),
            json_encode($event->getParams())
        ];

        $thread = static::getPosixThreadBridge()->getNewThread();

        static::$id++;

        Runtime::runGarbageCollector();
        $thread->setServerVariables($_SERVER);
        $thread->setApplicationArguments($argv);
        $thread->setWorkerId(static::$id);
        $thread->setIpcAddress($event->getParam(ModuleDecorator::ZEUS_IPC_ADDRESS_PARAM));
        $thread->start(static::PTHREADS_INHERIT_NONE);
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
            $this->getDecorator()->getLogger()->warn(sprintf("Thread safety in PHP %s is broken: pthreads MPM may be unstable!", phpversion(), self::MIN_STABLE_PHP_VERSION));
        }
    }

    public static function getCapabilities() : MultiProcessingModuleCapabilities
    {
        $capabilities = new MultiProcessingModuleCapabilities();
        $capabilities->setIsolationLevel($capabilities::ISOLATION_THREAD);
        $capabilities->setSharedInitialAddressSpace(true);

        return $capabilities;
    }
}