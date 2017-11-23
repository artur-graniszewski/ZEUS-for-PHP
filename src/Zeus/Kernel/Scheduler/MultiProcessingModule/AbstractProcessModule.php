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
        $this->setIpcAddress('tcp://' . $event->getParam(MultiProcessingModuleInterface::ZEUS_IPC_ADDRESS_PARAM));
    }

    /**
     * @param SchedulerEvent $event
     */
    public function onWorkerTerminate(SchedulerEvent $event)
    {
        $uid = $event->getParam('uid');
        $useSoftTermination = $event->getParam('soft', false);
        if ($useSoftTermination || !$this->getPcntlBridge()->isSupported()) {
            parent::onWorkerTerminate($event);

            return;
        } else {
            $this->getPcntlBridge()->posixKill($uid, $useSoftTermination ? SIGINT : SIGKILL);
        }

        if (!isset($this->workers[$uid])) {
            $this->getLogger()->warn("Trying to stop already detached process $uid");
        }
    }

    /**
     * @return PcntlBridgeInterface
     */
    protected static function getPcntlBridge()
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

    /**
     * @return $this
     */
    protected function checkWorkers()
    {
        if ($this->getPcntlBridge()->isSupported()) {
            while ($this->workers && ($pid = $this->getPcntlBridge()->pcntlWait($pcntlStatus, WNOHANG|WUNTRACED)) > 0) {
                $this->raiseWorkerExitedEvent($pid, $pid, 1);
            }
        }

        parent::checkWorkers();

        return $this;
    }

    /**
     * @return MultiProcessingModuleCapabilities
     */
    public function getCapabilities() : MultiProcessingModuleCapabilities
    {
        $capabilities = new MultiProcessingModuleCapabilities();
        $capabilities->setIsolationLevel($capabilities::ISOLATION_PROCESS);

        return $capabilities;
    }
}