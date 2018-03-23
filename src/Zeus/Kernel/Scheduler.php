<?php

namespace Zeus\Kernel;

use Throwable;
use Zend\Log\Logger;
use Zeus\IO\Stream\AbstractStreamSelector;
use Zeus\Kernel\IpcServer\IpcEvent;
use Zeus\Kernel\IpcServer\Message;
use Zeus\Kernel\Scheduler\AbstractService;
use Zeus\Kernel\Scheduler\ConfigInterface;
use Zeus\Kernel\Scheduler\Exception\SchedulerException;
use Zeus\Kernel\Scheduler\Helper\PluginRegistry;
use Zeus\Kernel\Scheduler\MultiProcessingModule\MultiProcessingModuleInterface;
use Zeus\Kernel\Scheduler\Discipline\DisciplineInterface;
use Zeus\Kernel\Scheduler\Reactor;
use Zeus\Kernel\Scheduler\SchedulerEvent;
use Zeus\Kernel\Scheduler\Shared\WorkerCollection;
use Zeus\Kernel\Scheduler\Status\StatusMessage;
use Zeus\Kernel\Scheduler\Status\WorkerState;
use Zeus\Kernel\Scheduler\Worker;
use Zeus\Kernel\Scheduler\WorkerEvent;
use Zeus\Kernel\Scheduler\WorkerFlowManager;
use Zeus\Kernel\System\Runtime;
use Zeus\ServerService\Shared\Logger\ExceptionLoggerTrait;

use function microtime;
use function sprintf;
use function array_keys;
use function array_merge;
use function file_get_contents;
use function file_put_contents;
use function unlink;

/**
 * Class Scheduler
 * @package Zeus\Kernel\Scheduler
 * @internal
 */
final class Scheduler extends AbstractService
{
    use PluginRegistry;
    use ExceptionLoggerTrait;

    /** @var WorkerState */
    private $status;

    /** @var WorkerState[]|WorkerCollection */
    private $workers = [];

    /** @var DisciplineInterface */
    private $discipline;

    /** @var mixed[] */
    private $eventHandles;

    /** @var MultiProcessingModuleInterface */
    private $multiProcessingModule;

    /** @var SchedulerEvent */
    private $schedulerEvent;

    /** @var WorkerFlowManager */
    private $workerFlowManager;

    /** @var Reactor */
    private $reactor;

    const WORKER_SERVER = 'server';
    const WORKER_INIT = 'initWorker';

    public function setSchedulerEvent(SchedulerEvent $event)
    {
        $this->schedulerEvent = $event;
    }

    public function getSchedulerEvent() : SchedulerEvent
    {
        return clone $this->schedulerEvent;
    }

    public function getStatus() : WorkerState
    {
        return $this->status;
    }

    public function getReactor() : Reactor
    {
        return $this->reactor;
    }

    public function __construct(ConfigInterface $config, DisciplineInterface $discipline)
    {
        $this->setReactor(new Reactor());
        $this->workerFlowManager = new WorkerFlowManager();
        $this->workerFlowManager->setScheduler($this);

        $this->setConfig($config);
        $this->status = new WorkerState($config->getServiceName());
        $this->workers = new WorkerCollection($config->getMaxProcesses());

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
        $this->eventHandles[] = $eventManager->attach(SchedulerEvent::EVENT_STOP, function(SchedulerEvent $e) {
            $this->log(Logger::NOTICE, "Scheduler shutting down");
            $this->onShutdown($e);
        }, SchedulerEvent::PRIORITY_REGULAR);

        $sharedEventManager->attach(IpcServer::class, IpcEvent::EVENT_MESSAGE_RECEIVED, function(IpcEvent $e) { $this->onIpcMessage($e);});

        $this->eventHandles[] = $eventManager->attach(SchedulerEvent::EVENT_START, function() use ($eventManager) {
            $this->eventHandles[] = $eventManager->attach(WorkerEvent::EVENT_CREATE, function(WorkerEvent $e) { $this->registerNewWorker($e);}, WorkerEvent::PRIORITY_FINALIZE);
            $this->log(Logger::NOTICE, "Scheduler started");
            $this->startWorkers($this->getConfig()->getStartProcesses());
        }, SchedulerEvent::PRIORITY_FINALIZE);

        $this->eventHandles[] = $eventManager->attach(SchedulerEvent::EVENT_LOOP, function() {
            $this->manageWorkers();
        });

        $this->eventHandles[] = $eventManager->attach(WorkerEvent::EVENT_CREATE,
            // scheduler init
            function (WorkerEvent $event) use ($eventManager) {
                if (!$event->getParam(static::WORKER_SERVER) || $event->getParam(static::WORKER_INIT)) {
                    return;
                }

                $pid = $event->getWorker()->getStatus()->getProcessId();

                $fileName = $this->getUidFile();
                if (!@file_put_contents($fileName, $pid)) {
                    throw new SchedulerException(sprintf("Could not write to PID file: %s, aborting", $fileName), SchedulerException::LOCK_FILE_ERROR);
                }

                $this->kernelLoop();
            }, WorkerEvent::PRIORITY_FINALIZE
        );

        $this->eventHandles[] = $eventManager->attach(WorkerEvent::EVENT_INIT,
            // scheduler init
            function (WorkerEvent $event) use ($eventManager) {
                if (!$event->getParam(static::WORKER_SERVER)) {
                    return;
                }
                $event->stopPropagation(true);
                $this->startLifeCycle();
                $event->getWorker()->setTerminating(true);

                $eventManager->attach(WorkerEvent::EVENT_EXIT, function(WorkerEvent $event) {
                    $event->stopPropagation(true);
                }, SchedulerEvent::PRIORITY_INITIALIZE + 2);

            }, WorkerEvent::PRIORITY_INITIALIZE
        );

        $this->eventHandles[] = $eventManager->attach(WorkerEvent::EVENT_INIT, function(WorkerEvent $event) use ($eventManager) {
            Runtime::setUncaughtExceptionHandler([$event->getWorker(), 'terminate']);

            $eventManager->attach(WorkerEvent::EVENT_RUNNING, function(WorkerEvent $event) {
                $this->sendStatus($event);
            }, SchedulerEvent::PRIORITY_FINALIZE + 1);

            $eventManager->attach(WorkerEvent::EVENT_WAITING, function(WorkerEvent $event) {
                $this->sendStatus($event);
            }, SchedulerEvent::PRIORITY_FINALIZE + 1);

            $eventManager->attach(WorkerEvent::EVENT_EXIT, function(WorkerEvent $event) {
                $this->sendStatus($event);
            }, SchedulerEvent::PRIORITY_FINALIZE + 2);

        }, WorkerEvent::PRIORITY_FINALIZE + 1);

        $this->eventHandles[] = $eventManager->attach(WorkerEvent::EVENT_INIT, function(WorkerEvent $event) {
            $this->workerFlowManager->workerLoop($event->getWorker());
        }, WorkerEvent::PRIORITY_FINALIZE);
    }

    public function syncWorker(Worker $worker)
    {

    }

    /**
     * @param WorkerEvent $event
     * @todo: move this to an AbstractProcess or a Plugin?
     */
    private function sendStatus(WorkerEvent $event)
    {
        $worker = $event->getWorker();
        $status = $worker->getStatus();
        $status->updateStatus();

        $payload = [
            'type' => Message::IS_STATUS,
            'extra' => [
                'logger' => __CLASS__,
                'status' => $status->toArray()
            ]
        ];

        $message = new StatusMessage($payload);

        try {
            $this->getIpc()->send($message, IpcServer::AUDIENCE_SERVER);
        } catch (Throwable $exception) {
            $this->logException($exception, $this->getLogger());
            $worker->setTerminating(true);
            $event->setParam('exception', $exception);
        }
    }

    private function onIpcMessage(IpcEvent $event)
    {
        $message = $event->getParams();

        if (!($message instanceof StatusMessage)) {
            return;
        }

        $message = $message->getParams();

        /** @var WorkerState $workerState */
        $workerState = WorkerState::fromArray($message['extra']['status']);
        $uid = $workerState->getUid();

        // worker status changed, update this information server-side
        if (isset($this->workers[$uid])) {
            if ($this->workers[$uid]->getCode() !== $workerState->getCode()) {
                $workerState->setTime(microtime(true));
            }

            $this->workers[$uid] = $workerState;
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
        $uid = $event->getWorker()->getStatus()->getUid();

        $this->log(Logger::DEBUG, "Worker $uid exited");

        if (isset($this->workers[$uid])) {
            $workerState = $this->workers[$uid];

            if (!$workerState->isExiting() && $workerState->getTime() < microtime(true) - $this->getConfig()->getProcessIdleTimeout()) {
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
        $this->log(Logger::DEBUG, "Stopping scheduler");
        $fileName = $this->getUidFile();

        $uid = @file_get_contents($fileName);
        if (!$uid) {
            throw new SchedulerException("Scheduler not running: " . $fileName, SchedulerException::SCHEDULER_NOT_RUNNING);
        }

        $this->setTerminating(true);

        $this->log(Logger::INFO, "Terminating scheduler $uid");
        $worker = new WorkerState($this->getConfig()->getServiceName(), WorkerState::RUNNING);
        $worker->setUid($uid);
        $this->stopWorker($worker, true);
        $this->log(Logger::INFO, "Workers checked");
        $this->triggerEvent(SchedulerEvent::INTERNAL_EVENT_KERNEL_STOP);

        unlink($fileName);
    }

    private function getUidFile() : string
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
        $extraData = array_merge(['status' => $this->status], $extraData, ['service_name' => $this->getConfig()->getServiceName()]);
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

        try {
            if (!$launchAsDaemon) {
                $this->startLifeCycle();
                $this->kernelLoop();

                return;
            }
            $this->triggerEvent(SchedulerEvent::INTERNAL_EVENT_KERNEL_START);
            $this->workerFlowManager->startWorker([Scheduler::WORKER_SERVER => true]);

        } catch (Throwable $exception) {
            $this->handleException($exception);
        }
    }

    private function startLifeCycle()
    {
        $this->triggerEvent(SchedulerEvent::INTERNAL_EVENT_KERNEL_START);
        $this->triggerEvent(SchedulerEvent::EVENT_START);
        $this->mainLoop();
        // @fixme: kernelLoop() should be merged with mainLoop()
        $this->triggerEvent(SchedulerEvent::EVENT_STOP);
        $this->log(Logger::NOTICE, "Scheduler terminated");
    }

    private function handleException(Throwable $exception)
    {
        $this->triggerEvent(SchedulerEvent::EVENT_STOP, ['exception' => $exception]);
    }

    private function stopWorker(WorkerState $worker, bool $isSoftStop)
    {
        $uid = $worker->getUid();
        $this->log(Logger::DEBUG, sprintf('Terminating worker %d', $uid));
        $this->workerFlowManager->stopWorker($worker, $isSoftStop);

        if (isset($this->workers[$uid])) {
            $workerState = $this->workers[$uid];
            $workerState->setTime(microtime(true));
            $workerState->setCode(WorkerState::TERMINATED);
        }
    }

    private function onShutdown(SchedulerEvent $event)
    {
        $exception = $event->getParam('exception', null);

        if ($exception) {
            $this->logException($exception, $this->getLogger());
        }

        $this->setTerminating(true);

        $this->log(Logger::DEBUG, "Terminating workers");

        if ($this->workers) {
            foreach ($this->workers as $worker) {
                $this->stopWorker($worker, false);
            }
        }
    }

    private function startWorkers(int $amount)
    {
        if ($amount === 0) {
            return;
        }

        for ($i = 0; $i < $amount; ++$i) {
            $this->workerFlowManager->startWorker([]);
        }
    }

    private function registerNewWorker(WorkerEvent $event)
    {
        $status = $event->getWorker()->getStatus();
        $uid = $status->getUid();
        $status->setCode(WorkerState::WAITING);

        $this->workers[$uid] =
            $status;
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

        $toTerminate = $discipline->getWorkersToTerminate();
        $toCreate = $discipline->getAmountOfWorkersToCreate();

        $this->stopWorkers($toTerminate, true);
        $this->startWorkers($toCreate);
    }

    /**
     * @param WorkerState[] $workers
     * @param bool $isSoftTermination
     */
    private function stopWorkers(array $workers, bool $isSoftTermination)
    {
        foreach ($workers as $worker) {
            $this->stopWorker($worker, $isSoftTermination);
        }
    }

    /**
     * Starts main (infinite) loop.
     */
    private function mainLoop()
    {
        $reactor = $this->getReactor();

        $terminator = function() use ($reactor) {
            $this->triggerEvent(SchedulerEvent::EVENT_LOOP);
            if ($this->isTerminating()) {
                $reactor->setTerminating(true);
            }
        };

        do {
            $reactor->mainLoop(
                $terminator
            );
        } while (!$this->isTerminating());
    }

    private function kernelLoop()
    {
        $reactor = $this->getReactor();

        $terminator = function() use ($reactor) {
            $this->triggerEvent(SchedulerEvent::INTERNAL_EVENT_KERNEL_LOOP);
            if ($this->isTerminating()) {
                $reactor->setTerminating(true);
            }
        };
        do {
            $reactor->mainLoop(
                $terminator
            );
        } while (!$this->isTerminating());
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

    public function observeSelector(AbstractStreamSelector $selector, $onSelectCallback, $onTimeoutCallback, int $timeout)
    {
        $this->getReactor()->observe($selector,
            function(AbstractStreamSelector $selector) use ($onSelectCallback) {
                $event = $this->getSchedulerEvent();
                $event->setName(SchedulerEvent::EVENT_SELECT);
                $event->setParam('selector', $selector);
                $this->getEventManager()->triggerEvent($event);
                $onSelectCallback($selector);
            },
            function(AbstractStreamSelector $selector) use ($onTimeoutCallback) {
                $event = $this->getSchedulerEvent();
                $event->setName(SchedulerEvent::EVENT_SELECT_TIMEOUT);
                $event->setParam('selector', $selector);
                $this->getEventManager()->triggerEvent($event);
                $onTimeoutCallback($selector);
            },
            $timeout);
    }

    public function setReactor(Reactor $reactor)
    {
        $this->reactor = $reactor;
    }
}