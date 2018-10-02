<?php

namespace Zeus\Kernel\Scheduler\MultiProcessingModule;

use LogicException;
use RuntimeException;
use Throwable;
use Zend\EventManager\EventManagerAwareInterface;
use Zend\EventManager\EventManagerAwareTrait;
use Zend\EventManager\EventsCapableInterface;
use Zend\Log\LoggerAwareTrait;
use Zeus\Kernel\Scheduler\MultiProcessingModule\Listener\KernelLoopListener;
use Zeus\Kernel\Scheduler\MultiProcessingModule\Listener\KernelStartListener;
use Zeus\Kernel\Scheduler\MultiProcessingModule\Listener\KernelStopListener;
use Zeus\Kernel\Scheduler\MultiProcessingModule\Listener\PipeListener;
use Zeus\Kernel\Scheduler\MultiProcessingModule\Listener\SchedulerInitListener;
use Zeus\Kernel\Scheduler\MultiProcessingModule\Listener\SchedulerLoopListener;
use Zeus\Kernel\Scheduler\MultiProcessingModule\Listener\SchedulerStopListener;
use Zeus\Kernel\Scheduler\MultiProcessingModule\Listener\WorkerCreateListener;
use Zeus\Kernel\Scheduler\MultiProcessingModule\Listener\WorkerExitListener;
use Zeus\Kernel\Scheduler\MultiProcessingModule\Listener\WorkerInitListener;
use Zeus\Kernel\Scheduler\MultiProcessingModule\Listener\WorkerLoopListener;
use Zeus\Kernel\Scheduler\MultiProcessingModule\Listener\WorkerStopListener;
use Zeus\Kernel\Scheduler\MultiProcessingModule\Listener\WorkerTerminateListener;
use Zeus\Kernel\Scheduler\SchedulerEvent;
use Zeus\Kernel\Scheduler\Status\WorkerState;
use Zeus\Kernel\Scheduler\Command\CreateWorker;
use Zeus\Kernel\Scheduler\WorkerEvent;
use Zeus\IO\Stream\Selector;

use function get_class;
use function sprintf;
use function is_callable;
use function sleep;
use function time;
use function count;
use function array_search;
use function stream_socket_client;
use function in_array;
use Zeus\Kernel\Scheduler\Command\TerminateWorker;
use Zeus\Kernel\Scheduler\Command\InitializeWorker;
use Zeus\Kernel\Scheduler\Event\SchedulerLoopRepeated;
use Zeus\Kernel\Scheduler\Event\WorkerLoopRepeated;
use Zeus\Kernel\Scheduler\Event\WorkerExited;
use Zeus\Kernel\Scheduler\Event\WorkerTerminated;

class ModuleDecorator implements EventsCapableInterface, EventManagerAwareInterface
{
    use EventManagerAwareTrait;
    use LoggerAwareTrait;

    const LOOPBACK_INTERFACE = 'tcp://127.0.0.1';
    const UPSTREAM_CONNECTION_TIMEOUT = 5;
    const ZEUS_IPC_ADDRESS_PARAM = 'zeusIpcAddress';
    const ZEUS_IPC_PIPE_PARAM = 'zeusIpcPipe';

    /** @var int */
    private $ipcAddress;

    /** @var MultiProcessingModuleInterface */
    private $driver;

    /** @var WorkerEvent */
    private $workerEvent;

    /** @var Selector */
    private $ipcSelector;

    /** @var WorkerPool */
    private $workerPool;

    /** @var WorkerIPC */
    private $workerIPC;

    public function __construct(MultiProcessingModuleInterface $driver)
    {
        $errorMessage = '';
        if (!$driver::isSupported($errorMessage)) {
            throw new RuntimeException($errorMessage);
        }

        $this->driver = $driver;
        $this->driver->setDecorator($this);

        $this->ipcSelector = new Selector();
        $this->workerPool = new WorkerPool($this->ipcSelector);
        $this->workerIPC = new WorkerIPC($this->workerPool);
    }

    public function setWorkerEvent(WorkerEvent $event)
    {
        $this->workerEvent = $event;
    }

    public function getWorkerEvent(): WorkerEvent
    {
        if (!$this->workerEvent) {
            throw new LogicException("Worker event not set");
        }

        $workerEvent = clone $this->workerEvent;
        $workerEvent->setParams([]);
        //$workerEvent->getWorker()->setTerminating(false);

        return $workerEvent;
    }

    public function getIpcAddress(): string
    {
        return $this->ipcAddress;
    }

    public function setIpcAddress(string $address)
    {
        $this->ipcAddress = $address;
    }

    protected function attachDefaultListeners()
    {
        $eventManager = $this->getEventManager();

        $eventManager->attach(SchedulerEvent::EVENT_START, function (SchedulerEvent $e) {
            $this->logCapabilities();
        }, SchedulerEvent::PRIORITY_INITIALIZE);

        $eventManager->attach(WorkerLoopRepeated::class, new PipeListener($this->driver, $this->workerPool, $this->workerIPC), WorkerEvent::PRIORITY_INITIALIZE);
        $eventManager->attach(SchedulerLoopRepeated::class, new PipeListener($this->driver, $this->workerPool, $this->workerIPC), -9000);
        $eventManager->attach(CreateWorker::class, function (WorkerEvent $event) {
            $pipe = $this->workerIPC->createPipe();
            $event->setParam(static::ZEUS_IPC_ADDRESS_PARAM, $pipe->getLocalAddress());
            $event->setParam(static::ZEUS_IPC_PIPE_PARAM, $pipe);
        }, WorkerEvent::PRIORITY_FINALIZE + 1);

        $eventManager->attach(CreateWorker::class, new WorkerCreateListener($this->driver, $this->workerPool), WorkerEvent::PRIORITY_FINALIZE + 1);
        $eventManager->attach(WorkerTerminated::class, new WorkerStopListener($this->driver), WorkerEvent::PRIORITY_FINALIZE);
        $eventManager->attach(SchedulerEvent::INTERNAL_EVENT_KERNEL_START, new KernelStartListener($this->driver));
        $eventManager->attach(SchedulerEvent::EVENT_START, new SchedulerInitListener($this->driver), -9000);
        $eventManager->attach(SchedulerEvent::EVENT_STOP, new SchedulerStopListener($this->driver, $this->workerPool), SchedulerEvent::PRIORITY_FINALIZE);
        $eventManager->attach(SchedulerLoopRepeated::class, new SchedulerLoopListener($this->driver, $this->workerPool), -9000);
        $eventManager->attach(WorkerLoopRepeated::class, new WorkerLoopListener($this->driver, $this->workerPool), WorkerEvent::PRIORITY_INITIALIZE);
        $eventManager->attach(TerminateWorker::class, new WorkerTerminateListener($this->driver, $this->workerPool), -9000);
        $eventManager->attach(SchedulerEvent::INTERNAL_EVENT_KERNEL_LOOP, new KernelLoopListener($this->driver, $this->workerPool), -9000);
        $eventManager->attach(SchedulerEvent::INTERNAL_EVENT_KERNEL_STOP, new KernelStopListener($this->driver, $this->workerPool));
        $eventManager->attach(InitializeWorker::class, new WorkerInitListener($this->driver), WorkerEvent::PRIORITY_INITIALIZE + 1);
        $eventManager->attach(InitializeWorker::class, function () use ($eventManager) {
            if (!$this->workerPool->isTerminating()) {
                try {
                    $this->workerIPC->connectToPipe($this->getIpcAddress());
                    $this->workerIPC->checkPipe();
                } catch (Throwable $e) {
                    $this->workerPool->setTerminating(true);
                }
            }
            $eventManager->attach(WorkerExited::class, new WorkerExitListener($this->driver), WorkerEvent::PRIORITY_FINALIZE);
        }, WorkerEvent::PRIORITY_INITIALIZE + 1);

        $eventManager->attach(SchedulerEvent::INTERNAL_EVENT_KERNEL_STOP, function() {
            $this->workerIPC->closePipe();
        });

        if (is_callable([$this->driver, 'attach'])) {
            $this->driver->attach($eventManager);
        }
    }

    private function logCapabilities()
    {
        $driver = $this->driver;
        $capabilities = $driver::getCapabilities();
        $driverName = get_class($driver);

        $logger = $this->getLogger();
        $logger->notice(sprintf("Using %s MPM module", substr($driverName, strrpos($driverName, '\\')+1)));
        $logger->info("Enumerating module capabilities:");
        $logger->info(sprintf("* Using %s isolation level", $capabilities->getIsolationLevelName()));
        $logger->info(sprintf("* Using %s signal handler", $capabilities->isAsyncSignalHandler() ? 'asynchronous': 'synchronous'));
        $logger->info(sprintf("* Parent memory pages are %s", $capabilities->isCopyingParentMemoryPages() ? 'copied': 'not copied'));
    }

    public function raiseWorkerExitedEvent(int $uid, int $processId, int $threadId)
    {
        // @todo: refactor this!
        $eventCopy = $this->getWorkerEvent();
        
        $event = new WorkerTerminated();
        $worker = $eventCopy->getWorker();
        $worker->setUid($uid);
        $worker->setProcessId($processId);
        $worker->setThreadId($threadId);
        $worker->setCode(WorkerState::TERMINATED);
        $event->setWorker($worker);
        $event->setScheduler($eventCopy->getScheduler());
        $event->setTarget($eventCopy->getScheduler());
        $this->getEventManager()->triggerEvent($event);
        $this->workerPool->unregisterWorker($uid);
    }
}