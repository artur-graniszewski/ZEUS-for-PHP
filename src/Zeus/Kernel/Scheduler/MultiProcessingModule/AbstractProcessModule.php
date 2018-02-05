<?php

namespace Zeus\Kernel\Scheduler\MultiProcessingModule;

use Zeus\Kernel\Scheduler\MultiProcessingModule\PosixProcess\PcntlBridge;
use Zeus\Kernel\Scheduler\MultiProcessingModule\PosixProcess\PcntlBridgeInterface;
use Zeus\Kernel\Scheduler\SchedulerEvent;
use Zeus\Kernel\Scheduler\WorkerEvent;

abstract class AbstractProcessModule extends AbstractModule
{
    protected $workers = [];

    /** @var PcntlBridgeInterface */
    protected static $pcntlBridge;

    public function onWorkerInit(WorkerEvent $event)
    {
        $this->getWrapper()->setIpcAddress('tcp://' . $event->getParam(ModuleWrapper::ZEUS_IPC_ADDRESS_PARAM));
    }

    public function onWorkerTerminate(WorkerEvent $event)
    {
        $uid = $event->getParam('uid');
        $useSoftTermination = $event->getParam('soft', false);
        if ($useSoftTermination || !$this->getPcntlBridge()->isSupported()) {

            return;
        } else {
            $this->getPcntlBridge()->posixKill($uid, $useSoftTermination ? SIGINT : SIGKILL);
        }

        if (!isset($this->workers[$uid])) {
            $this->getWrapper()->getLogger()->warn("Trying to stop already detached process $uid");
        }
    }

    protected abstract function createProcess(WorkerEvent $event) : int;

    public function onWorkerCreate(WorkerEvent $event)
    {
        $pid = $this->createProcess($event);

        $worker = $event->getWorker();
        $worker->setProcessId($pid);
        $worker->setThreadId(1);
        $worker->setUid($pid);
    }

    protected static function getPcntlBridge() : PcntlBridgeInterface
    {
        if (!isset(static::$pcntlBridge)) {
            static::$pcntlBridge = new PcntlBridge();
        }

        return static::$pcntlBridge;
    }

    /**
     * @param PcntlBridgeInterface $bridge
     */
    public static function setPcntlBridge(PcntlBridgeInterface $bridge)
    {
        static::$pcntlBridge = $bridge;
    }

    public function onWorkersCheck(SchedulerEvent $event)
    {
        $pcntlStatus = 0;

        if ($this->getPcntlBridge()->isSupported()) {
            while ($this->workers && ($pid = $this->getPcntlBridge()->pcntlWait($pcntlStatus, WNOHANG|WUNTRACED)) > 0) {
                $this->getWrapper()->raiseWorkerExitedEvent($pid, $pid, 1);
            }
        }
    }

    public static function getCapabilities() : MultiProcessingModuleCapabilities
    {
        $capabilities = new MultiProcessingModuleCapabilities();
        $capabilities->setIsolationLevel($capabilities::ISOLATION_PROCESS);

        return $capabilities;
    }
}