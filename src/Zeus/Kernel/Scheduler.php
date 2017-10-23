<?php

namespace Zeus\Kernel;

use Zend\EventManager\EventManager;
use Zend\EventManager\EventManagerInterface;
use Zend\EventManager\EventsCapableInterface;
use Zend\Log\Logger;
use Zend\Log\LoggerInterface;
use Zeus\Kernel\IpcServer\IpcEvent;
use Zeus\Kernel\Scheduler\AbstractService;
use Zeus\Kernel\Scheduler\Worker;
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

/**
 * Class Scheduler
 * @package Zeus\Kernel\Scheduler
 * @internal
 */
final class Scheduler extends AbstractService implements EventsCapableInterface
{
    use PluginRegistry;
    use GarbageCollector;

    /** @var WorkerState[]|WorkerCollection */
    protected $workers = [];

    /** @var bool */
    protected $isSchedulerTerminating = false;

    /** @var WorkerState */
    protected $schedulerStatus;

    /** @var Worker */
    protected $workerService;

    protected $discipline;

    /** @var mixed[] */
    protected $eventHandles;

    /** @var MultiProcessingModuleInterface */
    protected $multiProcessingModule;

    /** @var SchedulerEvent */
    protected $schedulerEvent;

    /**
     * @param SchedulerEvent $event
     * @return $this
     */
    public function setSchedulerEvent(SchedulerEvent $event)
    {
        $this->schedulerEvent = $event;

        return $this;
    }

    /**
     * @return SchedulerEvent
     */
    public function getSchedulerEvent() : SchedulerEvent
    {
        return clone $this->schedulerEvent;
    }

    /**
     * @return Worker
     */
    public function getWorkerService()
    {
        return $this->workerService;
    }

    /**
     * Scheduler constructor.
     * @param ConfigInterface $config
     * @param EventManagerInterface $eventManager
     * @param Worker $workerService
     * @param DisciplineInterface $discipline
     */
    public function __construct(ConfigInterface $config, EventManagerInterface $eventManager, Worker $workerService, DisciplineInterface $discipline)
    {
        $event = new SchedulerEvent();
        $event->setTarget($this);
        $event->setScheduler($this);
        $this->setSchedulerEvent($event);

        $this->setEventManager($eventManager);
        $this->discipline = $discipline;
        $this->setConfig($config);
        $this->workerService = $workerService;
        $this->status = new WorkerState($this->getConfig()->getServiceName());
        $this->workers = new WorkerCollection($this->getConfig()->getMaxProcesses());
    }

    public function __destruct()
    {
        foreach ($this->getPluginRegistry() as $plugin) {
            $this->removePlugin($plugin);
        }

        if ($this->eventHandles) {
            $events = $this->getEventManager();
            foreach ($this->eventHandles as $handle) {
                $events->detach($handle);
            }
        }
    }

    /**
     * @param EventManagerInterface $eventManager
     * @return $this
     */
    public function attach(EventManagerInterface $eventManager)
    {
        $this->setEventManager($eventManager);
        $sharedEventManager = $eventManager->getSharedManager();
        $this->eventHandles[] = $eventManager->attach(WorkerEvent::EVENT_WORKER_CREATE, function(SchedulerEvent $e) { $this->addNewWorker($e);}, SchedulerEvent::PRIORITY_FINALIZE);
        $this->eventHandles[] = $eventManager->attach(WorkerEvent::EVENT_WORKER_CREATE, function(WorkerEvent $e) { $this->onWorkerCreate($e);}, SchedulerEvent::PRIORITY_FINALIZE + 1);
        $this->eventHandles[] = $eventManager->attach(SchedulerEvent::EVENT_WORKER_TERMINATED, function(SchedulerEvent $e) { $this->onWorkerExited($e);}, SchedulerEvent::PRIORITY_FINALIZE);
        $this->eventHandles[] = $eventManager->attach(SchedulerEvent::EVENT_SCHEDULER_STOP, function(SchedulerEvent $e) { $this->onShutdown($e);}, SchedulerEvent::PRIORITY_REGULAR);
        $sharedEventManager->attach(IpcServer::class, IpcEvent::EVENT_MESSAGE_RECEIVED, function(IpcEvent $e) { $this->onIpcMessage($e);});
        $this->eventHandles[] = $eventManager->attach(SchedulerEvent::EVENT_SCHEDULER_START, function() {
            $this->onSchedulerStart();
        }, SchedulerEvent::PRIORITY_FINALIZE);

        $this->eventHandles[] = $eventManager->attach(SchedulerEvent::EVENT_SCHEDULER_LOOP, function() {
            $this->collectCycles();
            $this->manageWorkers($this->discipline);
        });

        $this->eventHandles[] = $eventManager->attach(SchedulerEvent::EVENT_SCHEDULER_START,
            function(SchedulerEvent $event) {
                $this->startWorkers($this->getConfig()->getStartProcesses());
                $this->mainLoop();
            }, SchedulerEvent::PRIORITY_FINALIZE);

        $this->eventHandles[] = $eventManager->attach(WorkerEvent::EVENT_WORKER_INIT,
            function(WorkerEvent $e) use ($eventManager) {
                if (!$e->getParam('server')) {
                    return;
                }

                $e->stopPropagation(true);
                $e->getWorker()->setIsTerminating(true);
                $this->triggerEvent(SchedulerEvent::EVENT_SCHEDULER_START);

            }, SchedulerEvent::PRIORITY_FINALIZE + 1);

        $this->eventHandles[] = $eventManager->attach(WorkerEvent::EVENT_WORKER_CREATE,
            function (SchedulerEvent $event) use ($eventManager) {
                if (!$event->getParam('server') || $event->getParam('init_process')) {
                    return;
                }

                $this->eventHandles[] = $eventManager->attach(WorkerEvent::EVENT_WORKER_INIT, function(WorkerEvent $e) {
                    $e->stopPropagation(true);
                }, WorkerEvent::PRIORITY_INITIALIZE + 100000);

                $pid = $event->getParam('uid');

                $fileName = sprintf("%s%s.pid", $this->getConfig()->getIpcDirectory(), $this->getConfig()->getServiceName());
                if (!@file_put_contents($fileName, $pid)) {
                    throw new SchedulerException(sprintf("Could not write to PID file: %s, aborting", $fileName), SchedulerException::LOCK_FILE_ERROR);
                }

                $this->kernelLoop();
            }
            , SchedulerEvent::PRIORITY_FINALIZE
        );


        return $this;
    }

    /**
     * @param IpcEvent $event
     */
    protected function onIpcMessage(IpcEvent $event)
    {
        $message = $event->getParams();

        if (!($message instanceof StatusMessage)) {
            return;
        }

        $message = $message->getParams();

        $details = $message['extra'];
        $pid = $details['uid'];
        $threadId = $details['threadId'];

        if ($threadId > 1) {
            $pid = $threadId;
        }

        /** @var WorkerState $processStatus */
        $processStatus = $message['extra']['status'];

        // worker status changed, update this information server-side
        if (isset($this->workers[$pid])) {
            if ($this->workers[$pid]['code'] !== $processStatus['code']) {
                $processStatus['time'] = microtime(true);
            }

            $this->workers[$pid] = $processStatus;
        }
    }

    /**
     * @param int $priority
     * @param string $message
     * @param mixed[] $extra
     * @return $this
     */
    protected function log($priority, $message, $extra = []) : Scheduler
    {
        if (!isset($extra['service_name'])) {
            $extra['service_name'] = $this->getConfig()->getServiceName();
        }

        if (!isset($extra['logger'])) {
            $extra['logger'] = __CLASS__;
        }

        $this->getLogger()->log($priority, $message, $extra);

        return $this;
    }

    /**
     * @param SchedulerEvent $event
     */
    protected function onWorkerExited(SchedulerEvent $event)
    {
        $id = $event->getParam('uid');
        $this->log(Logger::DEBUG, "Worker $id exited");

        if (isset($this->workers[$id])) {
            $processStatus = $this->workers[$id];

            if (!WorkerState::isExiting($processStatus) && $processStatus['time'] < microtime(true) - $this->getConfig()->getProcessIdleTimeout()) {
                $this->log(Logger::ERR, "Worker $id exited prematurely");
            }

            unset($this->workers[$id]);
        }
    }

    /**
     * Stops the process manager.
     *
     * @return $this
     */
    public function stop() : Scheduler
    {
        $this->getLogger()->debug("Stopping scheduler");
        $fileName = sprintf("%s%s.pid", $this->getConfig()->getIpcDirectory(), $this->getConfig()->getServiceName());

        $pid = @file_get_contents($fileName);
        if (!$pid) {
            throw new SchedulerException("Scheduler not running: " . $fileName, SchedulerException::SCHEDULER_NOT_RUNNING);
        }

        $this->setIsTerminating(true);
        $this->stopWorker($pid, true);

        unlink($fileName);

        return $this;
    }

    /**
     * @return string
     */
    public function getPidFile() : string
    {
        // @todo: make it more sophisticated
        $fileName = sprintf("%s%s.pid", $this->getConfig()->getIpcDirectory(), $this->getConfig()->getServiceName());

        return $fileName;
    }

    /**
     * @param string $eventName
     * @param mixed[]$extraData
     * @return $this
     */
    protected function triggerEvent(string $eventName, array $extraData = []) : Scheduler
    {
        $extraData = array_merge($this->status->toArray(), $extraData, ['service_name' => $this->getConfig()->getServiceName()]);
        $events = $this->getEventManager();
        $event = $this->getSchedulerEvent();
        $event->setParams($extraData);
        $event->setName($eventName);
        $events->triggerEvent($event);

        return $this;
    }

    /**
     * Creates the server instance.
     *
     * @param bool $launchAsDaemon Run this server as a daemon?
     * @return $this
     */
    public function start($launchAsDaemon = false) : Scheduler
    {
        $plugins = $this->getPluginRegistry()->count();
        $this->log(Logger::INFO, sprintf("Starting Scheduler with %d plugin%s", $plugins, $plugins !== 1 ? 's' : ''));
        $this->collectCycles();

        try {
            if (!$launchAsDaemon) {
                $this->triggerEvent(SchedulerEvent::INTERNAL_EVENT_KERNEL_START);
                $this->triggerEvent(SchedulerEvent::EVENT_SCHEDULER_START);
                // @fixme: kernelLoop() should be merged with mainLoop()
                $this->kernelLoop();

                return $this;
            }
            $this->triggerEvent(SchedulerEvent::INTERNAL_EVENT_KERNEL_START);
            $this->getMultiProcessingModule()->startWorker(['server' => true]);

        } catch (\Throwable $exception) {
            $this->handleException($exception);
        }

        return $this;
    }

    /**
     * @param \Throwable $exception
     * @return $this
     */
    protected function handleException(\Throwable $exception) : Scheduler
    {
        $this->triggerEvent(SchedulerEvent::EVENT_SCHEDULER_STOP, ['exception' => $exception]);

        return $this;
    }

    private function onSchedulerStart()
    {
        $this->log(Logger::NOTICE, "Scheduler started");
    }

    /**
     * @param int $uid
     * @param bool $isSoftStop
     * @return $this
     */
    protected function stopWorker(int $uid, bool $isSoftStop) : Scheduler
    {
        $this->getMultiProcessingModule()->stopWorker($uid, $isSoftStop);

        if (isset($this->workers[$uid])) {
            $workerState = $this->workers[$uid];
            $workerState['code'] = WorkerState::TERMINATED;
            $this->workers[$uid] = $workerState;
        }

        return $this;
    }

    /**
     * Shutdowns the server
     *
     * @param SchedulerEvent $event
     */
    protected function onShutdown(SchedulerEvent $event)
    {
        if ($this->isTerminating()) {
            return;
        }

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

        $this->log(Logger::NOTICE, "Scheduler terminated");
    }

    /**
     * Create processes
     *
     * @param int $count Number of processes to create.
     * @return $this
     */
    protected function startWorkers(int $count) : Scheduler
    {
        if ($count === 0) {
            return $this;
        }

        for ($i = 0; $i < $count; ++$i) {
            $this->getMultiProcessingModule()->startWorker();
        }

        return $this;
    }

    /**
     * @param WorkerEvent $event
     */
    protected function onWorkerCreate(WorkerEvent $event)
    {
        if (!$event->getParam('init_process') || $event->getParam('server')) {
            return;
        }

        $worker = $event->getWorker();
        $worker->setProcessId($event->getParam('uid'));
        $worker->setThreadId($event->getParam('threadId', 1));
        $this->collectCycles();
        $this->setIsTerminating(true);
    }

    /**
     * @param SchedulerEvent $event
     * @return $this
     */
    protected function addNewWorker(SchedulerEvent $event) : Scheduler
    {
        if ($event->getParam('server')) {
            return $this;
        }

        $pid = $event->getParam('uid');

        $this->workers[$pid] = [
            'code' => WorkerState::WAITING,
            'uid' => $pid,
            'time' => microtime(true),
            'service_name' => $this->getConfig()->getServiceName(),
            'requests_finished' => 0,
            'requests_per_second' => 0,
            'cpu_usage' => 0,
            'status_description' => '',
        ];

        return $this;
    }

    /**
     * Manages server workers.
     *
     * @param DisciplineInterface $discipline
     * @return $this
     */
    protected function manageWorkers(DisciplineInterface $discipline) : Scheduler
    {
        if ($this->isTerminating()) {
            return $this;
        }

        $operations = $discipline->manage($this->getConfig(), clone $this->workers);

        $toTerminate = $operations['terminate'];
        $toSoftTerminate = $operations['soft_terminate'];
        $toCreate = $operations['create'];

        $this->startWorkers($toCreate);
        $this->stopWorkers($toTerminate, false);
        $this->stopWorkers($toSoftTerminate, true);

        return $this;
    }

    /**
     * @param int[] $workerUids
     * @param $isSoftTermination
     * @return $this
     */
    protected function stopWorkers(array $workerUids, bool $isSoftTermination) : Scheduler
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

        return $this;
    }

    /**
     * Creates main (infinite) loop.
     *
     * @return $this
     */
    protected function mainLoop() : Scheduler
    {
        do {
            $this->triggerEvent(SchedulerEvent::EVENT_SCHEDULER_LOOP);
        } while (!$this->isTerminating());

        return $this;
    }

    /**
     * @return $this
     */
    public function kernelLoop() : Scheduler
    {
        while (!$this->isTerminating()) {
            $time = microtime(true);
            $this->triggerEvent(SchedulerEvent::EVENT_KERNEL_LOOP);
            $diff = microtime(true) - $time;

            if ($diff < 0.1) {
                $diff = 1 - $diff;
                // wait for 0.1 sec
                usleep($diff * 100000);
            }
        }

        return $this;
    }

    /**
     * @return WorkerCollection|WorkerState[]
     */
    public function getWorkers() : WorkerCollection
    {
        return $this->workers;
    }

    /**
     * @param MultiProcessingModuleInterface $driver
     * @return $this
     */
    public function setMultiProcessingModule(MultiProcessingModuleInterface $driver) : Scheduler
    {
        $this->multiProcessingModule = $driver;

        return $this;
    }

    /**
     * @return MultiProcessingModuleInterface
     */
    public function getMultiProcessingModule() : MultiProcessingModuleInterface
    {
        return $this->multiProcessingModule;
    }
}