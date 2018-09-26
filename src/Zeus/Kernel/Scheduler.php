<?php

namespace Zeus\Kernel;

use Throwable;
use Zend\EventManager\EventManagerAwareTrait;
use Zend\Log\LoggerAwareTrait;
use Zeus\IO\Stream\AbstractStreamSelector;
use Zeus\Kernel\IpcServer\IpcEvent;
use Zeus\Kernel\Scheduler\ConfigInterface;
use Zeus\Kernel\Scheduler\Helper\PluginRegistry;
use Zeus\Kernel\Scheduler\Listener\KernelLoopGenerator;
use Zeus\Kernel\Scheduler\Listener\SchedulerExitListener;
use Zeus\Kernel\Scheduler\Listener\SchedulerInitListener;
use Zeus\Kernel\Scheduler\Listener\SchedulerLifeCycle;
use Zeus\Kernel\Scheduler\Listener\SchedulerLoopListener;
use Zeus\Kernel\Scheduler\Listener\SchedulerStartListener;
use Zeus\Kernel\Scheduler\Listener\SchedulerStopListener;
use Zeus\Kernel\Scheduler\Listener\SchedulerTerminateListener;
use Zeus\Kernel\Scheduler\Listener\WorkerExitListener;
use Zeus\Kernel\Scheduler\Listener\WorkerInitListener;
use Zeus\Kernel\Scheduler\Listener\WorkerLifeCycle;
use Zeus\Kernel\Scheduler\Listener\WorkerStatusListener;
use Zeus\Kernel\Scheduler\MultiProcessingModule\MultiProcessingModuleInterface;
use Zeus\Kernel\Scheduler\Discipline\DisciplineInterface;
use Zeus\Kernel\Scheduler\Reactor;
use Zeus\Kernel\Scheduler\SchedulerEvent;
use Zeus\Kernel\Scheduler\SchedulerLifeCycleFacade;
use Zeus\Kernel\Scheduler\Shared\WorkerCollection;
use Zeus\Kernel\Scheduler\Status\WorkerState;
use Zeus\Kernel\Scheduler\WorkerEvent;
use Zeus\Kernel\Scheduler\WorkerLifeCycleFacade;
use Zeus\ServerService\Shared\Logger\ExceptionLoggerTrait;
use Zeus\Kernel\Scheduler\Command\CreateWorker;

use function sprintf;
use Zeus\Kernel\Scheduler\Command\TerminateWorker;
use Zeus\Kernel\Scheduler\Command\TerminateScheduler;
use Zeus\Kernel\Scheduler\Command\InitializeWorker;
use Zeus\Kernel\Scheduler\Event\SchedulerLoopRepeated;


/**
 * Class Scheduler
 * @package Zeus\Kernel\Scheduler
 * @internal
 */
class Scheduler implements SchedulerInterface
{
    use PluginRegistry;
    use ExceptionLoggerTrait;
    use EventManagerAwareTrait;
    use LoggerAwareTrait;

    /** @var IpcServer */
    private $ipcServer;

    /** @var ConfigInterface */
    private $config;

    /** @var bool */
    private $isTerminating = false;

    /** @var WorkerState */
    private $worker;

    /** @var WorkerState[]|WorkerCollection */
    private $workers = [];

    /** @var DisciplineInterface */
    private $discipline;

    /** @var mixed[] */
    private $eventHandles = [];

    /** @var MultiProcessingModuleInterface */
    private $multiProcessingModule;

    /** @var SchedulerEvent */
    private $schedulerEvent;

    /** @var WorkerLifeCycleFacade */
    private $workerLifeCycle;

    /** @var SchedulerLifeCycleFacade */
    private $schedulerLifeCycle;

    /** @var Reactor */
    private $reactor;

    /** @var WorkerEvent */
    private $workerEvent;

    public function setSchedulerEvent(SchedulerEvent $event)
    {
        $this->schedulerEvent = $event;
    }

    public function getConfig() : ConfigInterface
    {
        return $this->config;
    }

    public function setIpc(IpcServer $ipcAdapter)
    {
        $this->ipcServer = $ipcAdapter;
    }

    public function getIpc() : IpcServer
    {
        return $this->ipcServer;
    }

    public function setTerminating(bool $isTerminating)
    {
        $this->isTerminating = $isTerminating;
    }

    public function isTerminating() : bool
    {
        return $this->isTerminating;
    }

    public function getSchedulerEvent() : SchedulerEvent
    {
        if (!$this->schedulerEvent) {
            $event = new SchedulerEvent();
            $event->setTarget($this);
            $event->setScheduler($this);
            $event->setWorker($this->getWorker());
            $this->setSchedulerEvent($event);
            $this->schedulerEvent = $event;
        }
        return clone $this->schedulerEvent;
    }

    public function getWorkerEvent() : WorkerEvent
    {
        if (!$this->workerEvent) {
            $event = new WorkerEvent();
            $event->setScheduler($this);
            $event->setTarget($this);
            $event->setWorker(new WorkerState($this->getConfig()->getServiceName()));
            $this->workerEvent = $event;
        }

        return clone $this->workerEvent;
    }

    public function getWorker() : WorkerState
    {
        return $this->worker;
    }
    
    public function setWorker(WorkerState $worker)
    {
        $this->worker = $worker;
    }

    public function getReactor() : Reactor
    {
        return $this->reactor;
    }

    public function __construct(ConfigInterface $config, DisciplineInterface $discipline, Reactor $reactor, IpcServer $ipc, MultiProcessingModuleInterface $driver)
    {
        $this->config = $config;
        $this->worker = new WorkerState($config->getServiceName());
        $this->workers = new WorkerCollection($config->getMaxProcesses());
        $this->ipcServer = $ipc;

        $this->reactor = $reactor;
        $this->workerLifeCycle = new WorkerLifeCycleFacade();
        $this->workerLifeCycle->setScheduler($this);

        $this->schedulerLifeCycle = new SchedulerLifeCycleFacade();
        $this->schedulerLifeCycle->setScheduler($this);

        $this->multiProcessingModule = $driver;
        $workerEvent = new WorkerEvent();
        $this->workerLifeCycle->initWorkerEvent($workerEvent);
        $driver->getDecorator()->setWorkerEvent($workerEvent);

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
            $events = $this->getEventManager();
            foreach ($this->eventHandles as $handle) {
                $events->detach($handle);
            }
        }
    }

    protected function attachDefaultListeners()
    {
        $events = $this->getEventManager();
        $handles[] = $events->attach(IpcEvent::EVENT_MESSAGE_RECEIVED, new WorkerStatusListener($this->getWorkers()));
        $handles[] = $events->attach(WorkerEvent::EVENT_TERMINATED, new WorkerExitListener(), WorkerEvent::PRIORITY_FINALIZE);
        $handles[] = $events->attach(SchedulerEvent::EVENT_STOP, new SchedulerExitListener($this->workerLifeCycle), SchedulerEvent::PRIORITY_REGULAR);
        $handles[] = $events->attach(SchedulerEvent::EVENT_STOP, new SchedulerStopListener($this->workerLifeCycle), SchedulerEvent::PRIORITY_FINALIZE);
        $handles[] = $events->attach(TerminateScheduler::class, new SchedulerTerminateListener($this->workerLifeCycle), SchedulerEvent::PRIORITY_INITIALIZE);
        $handles[] = $events->attach(SchedulerEvent::EVENT_START, new SchedulerStartListener($this->workerLifeCycle), SchedulerEvent::PRIORITY_FINALIZE);
        $handles[] = $events->attach(SchedulerLoopRepeated::class, new SchedulerLoopListener($this->workerLifeCycle, $this->discipline));
        $handles[] = $events->attach(CreateWorker::class, new KernelLoopGenerator(), WorkerEvent::PRIORITY_FINALIZE);
        $handles[] = $events->attach(InitializeWorker::class, new WorkerLifeCycle($this), WorkerEvent::PRIORITY_FINALIZE);
        $handles[] = $events->attach(InitializeWorker::class, new SchedulerLifeCycle($this), WorkerEvent::PRIORITY_INITIALIZE + 1);
        $handles[] = $events->attach(InitializeWorker::class, new SchedulerInitListener($this->schedulerLifeCycle), WorkerEvent::PRIORITY_INITIALIZE + 1000);
        $handles[] = $events->attach(InitializeWorker::class, new WorkerInitListener(), WorkerEvent::PRIORITY_INITIALIZE + 1);
        $this->eventHandles = array_merge($this->eventHandles, $handles);
    }

    /**
     * @return WorkerCollection|WorkerState[]
     */
    public function getWorkers() : WorkerCollection
    {
        return $this->workers;
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

    public function syncWorker(WorkerState $worker)
    {
        $this->workerLifeCycle->syncWorker($worker);
    }

    /**
     * Creates the server instance.
     *
     * @param bool $launchAsDaemon Run this server as a daemon?
     * @throws Throwable
     */
    public function start(bool $launchAsDaemon = false)
    {
        $plugins = $this->getPluginRegistry()->count();
        $kernelStart = $this->getSchedulerEvent();
        $kernelStart->setName(SchedulerEvent::INTERNAL_EVENT_KERNEL_START);
        $this->getEventManager()->triggerEvent($kernelStart);
        $this->getLogger()->info(sprintf("Starting Scheduler with %d plugin%s", $plugins, $plugins !== 1 ? 's' : ''));

        try {
            $this->schedulerLifeCycle->start([]);

        } catch (Throwable $exception) {
            $this->logException($exception, $this->getLogger());
            throw $exception;
        }
    }

    /**
     * Stops the scheduler.
     */
    public function stop()
    {
        $this->setTerminating(true);
        $worker = $this->getWorker();
        $this->schedulerLifeCycle->stop($worker, false);
    }
}