<?php

namespace Zeus\Kernel\Scheduler\MultiProcessingModule;

use Zeus\Kernel\Scheduler\MultiProcessingModule\PosixProcess\PcntlBridge;
use Zeus\Kernel\Scheduler\MultiProcessingModule\PosixProcess\PcntlBridgeInterface;
use Zeus\Kernel\Scheduler\SchedulerEvent;

abstract class AbstractProcessModule extends AbstractModule
{
    protected $workers = [];

    /** @var PcntlBridgeInterface */
    protected static $pcntlBridge;

    /**
     * @param SchedulerEvent $event
     */
    public function onWorkerTerminate(SchedulerEvent $event)
    {
        $uid = $event->getParam('uid');
        $useSoftTermination = $event->getParam('soft', false);
        if ($useSoftTermination || !$this->getPcntlBridge()->isSupported()) {
            parent::onStopWorker($uid, $useSoftTermination);

            return;
        } else {
            $this->getPcntlBridge()->posixKill($uid, $useSoftTermination ? SIGINT : SIGKILL);
        }

        if (!isset($this->workers[$uid])) {
            $this->getLogger()->warn("Trying to stop already detached process $uid");
        }

        return;
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
     * @param int $uid
     * @param bool $useSoftTermination
     * @return $this
     */
    public function onStopWorker(int $uid, bool $useSoftTermination)
    {
        if ($useSoftTermination || !$this->getPcntlBridge()->isSupported()) {
            parent::onStopWorker($uid, $useSoftTermination);

            return $this;
        } else {
            $this->unregisterWorker($uid);
            $this->getPcntlBridge()->posixKill($uid, $useSoftTermination ? SIGINT : SIGKILL);
        }

        if (!isset($this->workers[$uid])) {
            $this->getLogger()->warn("Trying to stop already detached process $uid");

            return $this;
        }

        return $this;
    }

    public function onSchedulerLoop(SchedulerEvent $event)
    {
        $wasExiting = $this->isTerminating();

        $this->checkPipe();
        $this->checkWorkers();

        if ($this->isTerminating() && !$wasExiting) {
            $event->getScheduler()->setIsTerminating(true);
        }
    }

    /**
     * @return $this
     */
    public function checkWorkers()
    {
        parent::checkWorkers();
        if ($this->getPcntlBridge()->isSupported()) {
            while ($this->workers && ($pid = $this->getPcntlBridge()->pcntlWait($pcntlStatus, WNOHANG|WUNTRACED)) > 0) {
                $this->raiseWorkerExitedEvent($pid, $pid, 1);
            }
        }

        return $this;
    }
}