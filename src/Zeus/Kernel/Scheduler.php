<?php

namespace Zeus\Kernel;

use Zend\Log\Logger;
use Zeus\Kernel\IpcServer\IpcEvent;
use Zeus\Kernel\Scheduler\AbstractService;
use Zeus\Kernel\Scheduler\ConfigInterface;
use Zeus\Kernel\Scheduler\Exception\SchedulerException;
use Zeus\Kernel\Scheduler\Helper\GarbageCollector;
use Zeus\Kernel\Scheduler\Helper\PluginRegistry;
use Zeus\Kernel\Scheduler\MultiProcessingModule\MultiProcessingModuleInterface;
use Zeus\Kernel\Scheduler\Discipline\DisciplineInterface;
use Zeus\Kernel\Scheduler\SchedulerEvent;
use Zeus\Kernel\Scheduler\Shared\WorkerCollection;
use Zeus\Kernel\Scheduler\Status\StatusMessage;
use Zeus\Kernel\Scheduler\Status\WorkerState;
use Zeus\Kernel\Scheduler\WorkerEvent;
use Zeus\Kernel\Scheduler\WorkerFlowManager;

/**
 * Class Scheduler
 * @package Zeus\Kernel\Scheduler
 * @internal
 */
final class Scheduler extends AbstractService
{
    use PluginRegistry;
    use GarbageCollector;

    /** @var WorkerState */
    private $status;

    /** @var WorkerState[]|WorkerCollection */
    private $workers = [];

    private $discipline;

    /** @var mixed[] */
    private $eventHandles;

    /** @var MultiProcessingModuleInterface */
    private $multiProcessingModule;

    /** @var SchedulerEvent */
    private $schedulerEvent;

    /** @var WorkerFlowManager */
    private $workerFlowManager;

    public function setSchedulerEvent(SchedulerEvent $event)
    {
        $this->schedulerEvent = $event;
    }

    public function getSchedulerEvent() : SchedulerEvent
    {
        return clone $this->schedulerEvent;
    }

    public function __construct(ConfigInterface $config, DisciplineInterface $discipline)
    {
        $this->workerFlowManager = new WorkerFlowManager();
        $this->workerFlowManager->setScheduler($this);
        $event = new SchedulerEvent();
        $event->setTarget($this);
        $event->setScheduler($this);
        $this->setSchedulerEvent($event);

        $this->setConfig($config);
        $this->status = new WorkerState($this->getConfig()->getServiceName());
        $this->workers = new WorkerCollection($this->getConfig()->getMaxProcesses());

        $this->discipline = $discipline;
        $discipline->setConfig($config);
        $discipline->setWorkersCollection($this->workers);
    }

    public function __destruct()
    {
        foreach ($this->getPluginRegistry() as $plugin) {
            $this->removePlugin($plugin);
        }

        if ($this->eventHandles) {
            foreach ($this->eventHandles as $handle) {
                $this->getEventManager()->detach($handle);
            }
        }
    }

    public function attachDefaultListeners()
    {
        $eventManager = $this->getEventManager();

        $sharedEventManager = $eventManager->getSharedManager();
        $this->eventHandles[] = $eventManager->attach(WorkerEvent::EVENT_TERMINATED, function(WorkerEvent $e) { $this->onWorkerTerminated($e);}, SchedulerEvent::PRIORITY_FINALIZE);
        $this->eventHandles[] = $eventManager->attach(SchedulerEvent::EVENT_STOP, function(SchedulerEvent $e) { $this->onShutdown($e);}, SchedulerEvent::PRIORITY_REGULAR);
        $sharedEventManager->attach(IpcServer::class, IpcEvent::EVENT_MESSAGE_RECEIVED, function(IpcEvent $e) { $this->onIpcMessage($e);});
        $this->eventHandles[] = $eventManager->attach(SchedulerEvent::EVENT_START, function() use ($eventManager) {
            $this->eventHandles[] = $eventManager->attach(WorkerEvent::EVENT_CREATE, function(WorkerEvent $e) { $this->addNewWorker($e);}, WorkerEvent::PRIORITY_FINALIZE);
            $this->log(Logger::NOTICE, "Scheduler started");
            $this->startWorkers($this->getConfig()->getStartProcesses());
        }, SchedulerEvent::PRIORITY_FINALIZE);

        $this->eventHandles[] = $eventManager->attach(SchedulerEvent::EVENT_LOOP, function() {
            $this->collectCycles();
            $this->manageWorkers();
        });

        $this->eventHandles[] = $eventManager->attach(WorkerEvent::EVENT_INIT,
            function(WorkerEvent $e) use ($eventManager) {
                if (!$e->getParam('server')) {
                    return;
                }

                $e->stopPropagation(true);
                $this->startLifeCycle();
                $e->getWorker()->setIsTerminating(true);

            }, WorkerEvent::PRIORITY_FINALIZE + 1);

        $this->eventHandles[] = $eventManager->attach(WorkerEvent::EVENT_CREATE,
            function (WorkerEvent $event) use ($eventManager) {
                if (!$event->getParam('server') || $event->getParam('initWorker')) {
                    return;
                }

                $this->eventHandles[] = $eventManager->attach(WorkerEvent::EVENT_INIT, function(WorkerEvent $e) {
                    $e->stopPropagation(true);
                }, WorkerEvent::PRIORITY_INITIALIZE + 100000);

                $pid = $event->getWorker()->getProcessId();

                $fileName = sprintf("%s%s.pid", $this->getConfig()->getIpcDirectory(), $this->getConfig()->getServiceName());
                if (!@file_put_contents($fileName, $pid)) {
                    throw new SchedulerException(sprintf("Could not write to PID file: %s, aborting", $fileName), SchedulerException::LOCK_FILE_ERROR);
                }

                $this->kernelLoop();
            }, WorkerEvent::PRIORITY_FINALIZE
        );
    }

    private function onIpcMessage(IpcEvent $event)
    {
        $message = $event->getParams();

        if (!($message instanceof StatusMessage)) {
            return;
        }

        $message = $message->getParams();

        /** @var WorkerState $processStatus */
        $processStatus = $message['extra']['status'];
        $pid = $processStatus['uid'];

        // worker status changed, update this information server-side
        if (isset($this->workers[$pid])) {
            if ($this->workers[$pid]['code'] !== $processStatus['code']) {
                $processStatus['time'] = microtime(true);
            }

            $this->workers[$pid] = $processStatus;
        }
    }

    private function log(int $priority, string $message, array $extra = [])
    {
        if (!isset($extra['service_name'])) {
            $extra['service_name'] = $this->getConfig()->getServiceName();
        }

        if (!isset($extra['logger'])) {
            $extra['logger'] = __CLASS__;
        }

        $this->getLogger()->log($priority, $message, $extra);
    }

    /**
     * @param WorkerEvent $event
     */
    private function onWorkerTerminated(WorkerEvent $event)
    {
        $uid = $event->getWorker()->getUid();

        $this->log(Logger::DEBUG, "Worker $uid exited");

        if (isset($this->workers[$uid])) {
            $processStatus = $this->workers[$uid];

            if (!WorkerState::isExiting($processStatus) && $processStatus['time'] < microtime(true) - $this->getConfig()->getProcessIdleTimeout()) {
                $this->log(Logger::ERR, "Worker $uid exited prematurely");
            }

            unset($this->workers[$uid]);
        }
    }

    /**
     * Stops the scheduler.
     */
    public function stop()
    {
        $this->getLogger()->debug("Stopping scheduler");
        $fileName = sprintf("%s%s.pid", $this->getConfig()->getIpcDirectory(), $this->getConfig()->getServiceName());

        $uid = @file_get_contents($fileName);
        if (!$uid) {
            throw new SchedulerException("Scheduler not running: " . $fileName, SchedulerException::SCHEDULER_NOT_RUNNING);
        }

        $this->setIsTerminating(true);

        $this->log(Logger::INFO, "Terminating scheduler $uid");
        $this->stopWorker($uid, true);
        $this->log(Logger::INFO, "Workers checked");

        unlink($fileName);
    }

    public function getUidFile() : string
    {
        // @todo: make it more sophisticated
        $fileName = sprintf("%s%s.pid", $this->getConfig()->getIpcDirectory(), $this->getConfig()->getServiceName());

        return $fileName;
    }

    /**
     * @param string $eventName
     * @param mixed[] $extraData
     */
    private function triggerEvent(string $eventName, array $extraData = [])
    {
        $extraData = array_merge($this->status->toArray(), $extraData, ['service_name' => $this->getConfig()->getServiceName()]);
        $events = $this->getEventManager();
        $event = $this->getSchedulerEvent();
        $event->setParams($extraData);
        $event->setName($eventName);
        $events->triggerEvent($event);
    }

    /**
     * Creates the server instance.
     *
     * @param bool $launchAsDaemon Run this server as a daemon?
     */
    public function start(bool $launchAsDaemon = false)
    {
        $plugins = $this->getPluginRegistry()->count();
        $this->log(Logger::INFO, sprintf("Starting Scheduler with %d plugin%s", $plugins, $plugins !== 1 ? 's' : ''));
        $this->collectCycles();

        try {
            if (!$launchAsDaemon) {
                $this->startLifeCycle();
                $this->kernelLoop();

                return;
            }
            $this->triggerEvent(SchedulerEvent::INTERNAL_EVENT_KERNEL_START);
            $this->workerFlowManager->startWorker(['server' => true]);

        } catch (\Throwable $exception) {
            $this->handleException($exception);
        }
    }

    private function startLifeCycle()
    {
        $this->triggerEvent(SchedulerEvent::INTERNAL_EVENT_KERNEL_START);
        $this->triggerEvent(SchedulerEvent::EVENT_START);
        $this->mainLoop();
        // @fixme: kernelLoop() should be merged with mainLoop()
        $this->getLogger()->debug("Scheduler stop event triggering...");
        $this->triggerEvent(SchedulerEvent::EVENT_STOP);
        $this->log(Logger::NOTICE, "Scheduler terminated");
        $this->getLogger()->debug("Scheduler stop event finished");
    }

    private function handleException(\Throwable $exception)
    {
        $this->triggerEvent(SchedulerEvent::EVENT_STOP, ['exception' => $exception]);
    }

    /**
     * @param int $uid
     * @param bool $isSoftStop
     */
    private function stopWorker(int $uid, bool $isSoftStop)
    {
        $this->workerFlowManager->stopWorker($uid, $isSoftStop);

        if (isset($this->workers[$uid])) {
            $workerState = $this->workers[$uid];
            $workerState['code'] = WorkerState::TERMINATED;
            $this->workers[$uid] = $workerState;
        }
    }

    private function onShutdown(SchedulerEvent $event)
    {
        $exception = $event->getParam('exception', null);

        $this->log(Logger::DEBUG, "Shutting down" . ($exception ? ' with exception: ' . $exception->getMessage() : ''));
        if ($exception) {
            $status = $exception->getCode();
            $this->log(Logger::ERR, sprintf("Exception (%d): %s in %s:%d", $status, $exception->getMessage(), $exception->getFile(), $exception->getLine()));
            $this->getLogger()->debug(sprintf("Stack Trace:\n%s", $exception->getTraceAsString()));
        }

        $this->setIsTerminating(true);

        $this->log(Logger::INFO, "Terminating scheduled workers");

        if ($this->workers) {
            foreach (array_keys($this->workers->toArray()) as $uid) {
                $this->log(Logger::DEBUG, "Terminating worker $uid");
                $this->stopWorker($uid, false);
            }
        }
    }

    private function startWorkers(int $amount)
    {
        if ($amount === 0) {
            return;
        }

        for ($i = 0; $i < $amount; ++$i) {
            $this->workerFlowManager->startWorker();
        }
    }

    private function addNewWorker(WorkerEvent $event)
    {
        $uid = $event->getWorker()->getUid();

        $this->workers[$uid] = [
            'code' => WorkerState::WAITING,
            'uid' => $uid,
            'time' => microtime(true),
            'service_name' => $this->getConfig()->getServiceName(),
            'requests_finished' => 0,
            'requests_per_second' => 0,
            'cpu_usage' => 0,
            'status_description' => '',
        ];
    }

    /**
     * Manages scheduled workers.
     */
    private function manageWorkers()
    {
        if ($this->isTerminating()) {
            return;
        }

        $discipline = $this->discipline;

        $this->startWorkers($discipline->getAmountOfWorkersToCreate());
        $this->stopWorkers($discipline->getWorkersToTerminate(), true);
    }

    /**
     * @param int[] $workerUids
     * @param bool $isSoftTermination
     */
    private function stopWorkers(array $workerUids, bool $isSoftTermination)
    {
        $now = microtime(true);

        foreach ($workerUids as $uid) {
            $processStatus = $this->workers[$uid];
            $processStatus['code'] = WorkerState::TERMINATED;
            $processStatus['time'] = $now;
            $this->workers[$uid] = $processStatus;
            $this->log(Logger::DEBUG, sprintf('Terminating worker %d', $uid));
            $this->stopWorker($uid, $isSoftTermination);
        }
    }

    /**
     * Starts main (infinite) loop.
     */
    private function mainLoop()
    {
        do {
            $this->triggerEvent(SchedulerEvent::EVENT_LOOP);
        } while (!$this->isTerminating());

        $this->getLogger()->debug("Scheduler loop finished");
    }

    private function kernelLoop()
    {
        while (!$this->isTerminating()) {
            $time = microtime(true);
            $this->triggerEvent(SchedulerEvent::INTERNAL_EVENT_KERNEL_LOOP);
            $diff = microtime(true) - $time;

            if ($diff < 0.1) {
                $diff = 1 - $diff;
                // wait for 0.1 sec
                usleep($diff * 100000);
            }
        }
    }

    /**
     * @return WorkerCollection|WorkerState[]
     */
    public function getWorkers() : WorkerCollection
    {
        return $this->workers;
    }

    public function setMultiProcessingModule(MultiProcessingModuleInterface $driver)
    {
        $this->multiProcessingModule = $driver;
    }

    public function getMultiProcessingModule() : MultiProcessingModuleInterface
    {
        return $this->multiProcessingModule;
    }
}