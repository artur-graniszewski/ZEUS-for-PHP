<?php

namespace Zeus\Kernel\ProcessManager;

use Zend\Console\Console;
use Zend\EventManager\EventInterface;
use Zend\EventManager\EventManagerInterface;
use Zend\Log\LoggerInterface;
use Zeus\Kernel\IpcServer\Adapter\IpcAdapterInterface;
use Zeus\Kernel\ProcessManager\Exception\ProcessManagerException;
use Zeus\Kernel\ProcessManager\Helper\Logger;
use Zeus\Kernel\ProcessManager\Scheduler\Discipline\DisciplineInterface;
use Zeus\Kernel\ProcessManager\Scheduler\Discipline\LruDiscipline;
use Zeus\Kernel\ProcessManager\Scheduler\ProcessCollection;
use Zeus\Kernel\ProcessManager\Status\ProcessState;
use Zeus\Kernel\IpcServer\Message;
use Zeus\Kernel\ProcessManager\Status\ProcessTitle;
use Zeus\Kernel\ProcessManager\Helper\EventManager;

/**
 * Class Scheduler
 * @package Zeus\Kernel\ProcessManager
 * @internal
 */
final class Scheduler
{
    use Logger;
    use EventManager;

    /** @var ProcessState[]|ProcessCollection */
    protected $processes = [];

    /** @var Config */
    protected $config;

    /** @var float */
    protected $currentTime;

    /** @var bool */
    protected $continueMainLoop = true;

    /** @var int */
    protected $schedulerId;

    /** @var ProcessState */
    protected $schedulerStatus;

    /** @var Process */
    protected $processService;

    /** @var IpcAdapterInterface */
    protected $ipcAdapter;

    /** @var ProcessTitle */
    protected $processTitle;

    /** @var float */
    protected $startTime;

    protected $discipline;

    /** @var SchedulerEvent */
    private $event;

    /**
     * @return Config
     */
    public function getConfig()
    {
        return $this->config;
    }

    /**
     * @return int
     */
    public function getId()
    {
        return $this->schedulerId;
    }

    /**
     * @param int $schedulerId
     * @return $this
     */
    public function setId($schedulerId)
    {
        $this->schedulerId = $schedulerId;

        return $this;
    }

    /**
     * @return float
     */
    public function getCurrentTime()
    {
        return $this->currentTime;
    }

    /**
     * @param float $currentTime
     * @return $this
     */
    public function setCurrentTime($currentTime)
    {
        $this->currentTime = $currentTime;

        return $this;
    }

    /**
     * @return bool
     */
    public function isContinueMainLoop()
    {
        return $this->continueMainLoop;
    }

    /**
     * @param bool $continueMainLoop
     * @return $this
     */
    public function setContinueMainLoop($continueMainLoop)
    {
        $this->continueMainLoop = $continueMainLoop;

        return $this;
    }

    /**
     * Scheduler constructor.
     * @param mixed[] $config
     * @param Process $processService
     * @param LoggerInterface $logger
     * @param IpcAdapterInterface $ipcAdapter
     * @param SchedulerEvent $schedulerEvent
     * @param DisciplineInterface $discipline
     */
    public function __construct($config, Process $processService, LoggerInterface $logger, IpcAdapterInterface $ipcAdapter, SchedulerEvent $schedulerEvent, DisciplineInterface $discipline)
    {
        $this->discipline = $discipline;
        $this->config = new Config($config);
        $this->ipcAdapter = $ipcAdapter;
        $this->processService = $processService;
        $this->schedulerStatus = new ProcessState($this->config->getServiceName());
        $this->setLogger($logger);

        $this->processes = new ProcessCollection($this->config->getMaxProcesses());
        $this->setLoggerExtraDetails(['service' => $this->config->getServiceName()]);

        if (!Console::isConsole()) {
            throw new ProcessManagerException("This application must be launched from the Command Line Interpreter", ProcessManagerException::CLI_MODE_REQUIRED);
        }

        $this->processTitle = new ProcessTitle();
        $this->processTitle->attach($this->getEventManager());
        $this->event = $schedulerEvent;
        $this->event->setScheduler($this);
    }

    /**
     * @return IpcAdapterInterface
     */
    public function getIpcAdapter()
    {
        return $this->ipcAdapter;
    }

    /**
     * @param EventManagerInterface $events
     * @return $this
     */
    protected function attach(EventManagerInterface $events)
    {
        $events->attach(SchedulerEvent::EVENT_PROCESS_CREATED, function(SchedulerEvent $e) { $this->addNewProcess($e);}, -10000);
        $events->attach(SchedulerEvent::EVENT_PROCESS_INIT, function(SchedulerEvent $e) { $this->onProcessInit($e);});
        $events->attach(SchedulerEvent::EVENT_PROCESS_TERMINATED, function(SchedulerEvent $e) { $this->onProcessTerminated($e);}, -10000);
        $events->attach(SchedulerEvent::EVENT_PROCESS_EXIT, function(SchedulerEvent $e) { $this->onProcessExit($e); }, -10000);
        $events->attach(SchedulerEvent::EVENT_PROCESS_MESSAGE, function(SchedulerEvent $e) { $this->onProcessMessage($e);});
        $events->attach(SchedulerEvent::EVENT_SCHEDULER_STOP, function(SchedulerEvent $e) { $this->onShutdown($e);});
        $events->attach(SchedulerEvent::EVENT_SCHEDULER_STOP, function(SchedulerEvent $e) { $this->onProcessExit($e); }, -10000);

        $events->attach(SchedulerEvent::EVENT_SCHEDULER_LOOP, function() {
            $this->collectCycles();
            $this->handleMessages();
            $this->manageProcesses($this->discipline);
        });

        return $this;
    }

    /**
     * @param EventInterface $event
     */
    protected function onProcessMessage(EventInterface $event)
    {
        $this->ipcAdapter->send($event->getParams());
    }

    /**
     * @param SchedulerEvent $event
     */
    protected function onProcessExit(SchedulerEvent $event)
    {
        /** @var \Exception $exception */
        $exception = $event->getParam('exception');

        $status = $exception ? $exception->getCode(): 0;
        exit($status);
    }

    /**
     * @param SchedulerEvent $event
     */
    protected function onProcessTerminated(SchedulerEvent $event)
    {
        $pid = $event->getParam('uid');
        if ($pid === $this->getId()) {
            $this->log(\Zend\Log\Logger::DEBUG, "Scheduler is exiting...");
            $event = $this->event;
            $event->setName(SchedulerEvent::EVENT_SCHEDULER_STOP);
            $event->setParams($this->getEventExtraData());
            $this->getEventManager()->triggerEvent($event);
            return;
        }

        $this->log(\Zend\Log\Logger::DEBUG, "Process $pid exited");

        if (isset($this->processes[$pid])) {
            $processStatus = $this->processes[$pid];

            if (!ProcessState::isExiting($processStatus) && $processStatus['time'] < microtime(true) - $this->getConfig()->getProcessIdleTimeout()) {
                $this->log(\Zend\Log\Logger::ERR, "Process $pid exited prematurely");
            }

            unset($this->processes[$pid]);
        }
    }

    /**
     * Stops the process manager.
     *
     * @return $this
     */
    public function stop()
    {
        $fileName = sprintf("%s%s.pid", $this->getConfig()->getIpcDirectory(), $this->getConfig()->getServiceName());

        if ($pid = @file_get_contents($fileName)) {
            $pid = (int)$pid;

            if ($pid) {
                $event = $this->event;
                $event->setName(SchedulerEvent::EVENT_PROCESS_TERMINATE);
                $event->setParams($this->getEventExtraData(['uid' => $pid, 'soft' => true]));
                $this->events->triggerEvent($event);
                $this->log(\Zend\Log\Logger::INFO, "Server stopped");
                unlink($fileName);

                return $this;
            }
        }

        throw new ProcessManagerException("Server not running", ProcessManagerException::SERVER_NOT_RUNNING);
    }

    /**
     * @return mixed[]
     */
    public function getStatus()
    {
        $payload = [
            'isEvent' => false,
            'type' => Message::IS_STATUS_REQUEST,
            'priority' => '',
            'message' => 'fetchStatus',
            'extra' => [
                'uid' => $this->getId(),
                'logger' => __CLASS__
            ]
        ];

        $this->ipcAdapter->useChannelNumber(1);
        $this->ipcAdapter->send($payload);

        $timeout = 5;
        $result = null;
        do {
            $result = $this->ipcAdapter->receive();
            usleep(1000);
            $timeout--;
        } while (!$result && $timeout >= 0);

        $this->ipcAdapter->useChannelNumber(0);

        if ($result) {
            return $result['extra'];
        }

        return null;
    }

    /**
     * @param mixed[] $extraExtraData
     * @return mixed[]
     */
    private function getEventExtraData($extraExtraData = [])
    {
        $extraExtraData = array_merge($this->schedulerStatus->toArray(), $extraExtraData, ['service_name' => $this->config->getServiceName()]);
        return $extraExtraData;
    }

    /**
     * Creates the server instance.
     *
     * @param bool $launchAsDaemon Run this server as a daemon?
     * @return $this
     */
    public function start($launchAsDaemon = false)
    {
        $this->startTime = microtime(true);
        $this->log(\Zend\Log\Logger::INFO, "Starting server");
        $this->collectCycles();

        $events = $this->getEventManager();
        $events->attach(SchedulerEvent::EVENT_SCHEDULER_START, [$this, 'onSchedulerStart'], 0);
        $schedulerEvent = $this->event;
        $processEvent = $this->event;

        try {
            if (!$launchAsDaemon) {
                $this->getEventManager()->trigger(SchedulerEvent::INTERNAL_EVENT_KERNEL_START, $this, $this->getEventExtraData());
                $this->getEventManager()->trigger(SchedulerEvent::EVENT_SCHEDULER_START, $this, $this->getEventExtraData());

                return $this;
            }

            $events->attach(SchedulerEvent::EVENT_PROCESS_INIT, function(EventInterface $e) {
                if ($e->getParam('server')) {
                    $e->stopPropagation(true);
                    $this->getEventManager()->trigger(SchedulerEvent::EVENT_SCHEDULER_START, $this, $this->getEventExtraData());
                }
            }, 100000);

            $events->attach(SchedulerEvent::EVENT_PROCESS_CREATE,
                function (EventInterface $event) {
                    $pid = $event->getParam('uid');

                    if (!$event->getParam('server')) {
                        return;
                    }

                    if (!@file_put_contents(sprintf("%s%s.pid", $this->getConfig()->getIpcDirectory(), $this->config->getServiceName()), $pid)) {
                        throw new ProcessManagerException("Could not write to PID file, aborting", ProcessManagerException::LOCK_FILE_ERROR);
                    }

                    $event->stopPropagation(true);
                }
                , -10000
            );

            $processEvent->setName(SchedulerEvent::EVENT_PROCESS_CREATE);
            $processEvent->setParams($this->getEventExtraData(['server' => true]));
            $this->getEventManager()->triggerEvent($processEvent);

            $schedulerEvent->setName(SchedulerEvent::INTERNAL_EVENT_KERNEL_START);
            $schedulerEvent->setParams($this->getEventExtraData());
            $this->getEventManager()->triggerEvent($schedulerEvent);
        } catch (\Throwable $exception) {
            $this->handleException($exception);
        } catch (\Exception $exception) {
            $this->handleException($exception);
        }

        return $this;
    }

    /**
     * @param \Throwable|\Exception $exception
     * @return $this
     */
    private function handleException($exception)
    {
        $schedulerEvent = $this->event;
        $schedulerEvent->setName(SchedulerEvent::EVENT_SCHEDULER_STOP);
        $schedulerEvent->setParams($this->getEventExtraData());
        $schedulerEvent->setParam('exception', $exception);
        $this->getEventManager()->triggerEvent($schedulerEvent);

        return $this;
    }

    /**
     * @return $this
     */
    public function onSchedulerStart()
    {
        $this->log(\Zend\Log\Logger::DEBUG, "Scheduler starting...");

        $this->setId(getmypid());

        $this->attach($this->getEventManager());

        $this->log(\Zend\Log\Logger::INFO, "Scheduler started");
        $this->createProcesses($this->getConfig()->getStartProcesses());

        return $this->mainLoop();
    }

    /**
     * @return $this
     */
    protected function collectCycles()
    {
        $enabled = gc_enabled();
        gc_enable();
        if (function_exists('gc_mem_caches')) {
            // @codeCoverageIgnoreStart
            gc_mem_caches();
            // @codeCoverageIgnoreEnd
        }
        gc_collect_cycles();


        if (!$enabled) {
            // @codeCoverageIgnoreStart
            gc_disable();
            // @codeCoverageIgnoreEnd
        }

        return $this;
    }

    /**
     * Shutdowns the server
     *
     * @param EventInterface $event
     * @return $this
     */
    protected function onShutdown(EventInterface $event)
    {
        $exception = $event->getParam('exception', null);

        $this->setContinueMainLoop(false);

        $this->log(\Zend\Log\Logger::INFO, "Terminating scheduler");

        foreach (array_keys($this->processes->toArray()) as $pid) {
            $this->log(\Zend\Log\Logger::DEBUG, "Terminating process $pid");
            $event = $this->event;
            $event->setName(SchedulerEvent::EVENT_PROCESS_TERMINATE);
            $event->setParams($this->getEventExtraData(['uid' => $pid]));
            $this->events->triggerEvent($event);
        }

        $this->handleMessages();

        if ($exception) {
            $status = $exception->getCode();
            $this->log(\Zend\Log\Logger::ERR, sprintf("Exception (%d): %s in %s:%d", $status, $exception->getMessage(), $exception->getFile(), $exception->getLine()));
        }

        $this->log(\Zend\Log\Logger::INFO, "Scheduler terminated");

        $this->ipcAdapter->disconnect();
    }

    /**
     * Forks children
     *
     * @param int $count Number of processes to create.
     * @return $this
     */
    protected function createProcesses($count)
    {
        if ($count === 0) {
            return $this;
        }

        $this->setCurrentTime(microtime(true));

        for ($i = 0; $i < $count; ++$i) {
            $event = $this->event;
            $event->setName(SchedulerEvent::EVENT_PROCESS_CREATE);
            $event->setParams($this->getEventExtraData());
            $this->getEventManager()->triggerEvent($event);
        }

        return $this;
    }

    /**
     * @param SchedulerEvent $event
     */
    protected function onProcessInit(SchedulerEvent $event)
    {
        $pid = $event->getParam('uid');

        unset($this->processes);
        $this->collectCycles();
        $this->setContinueMainLoop(false);
        $this->ipcAdapter->useChannelNumber(1);

        $event->setProcess($this->processService);
        $this->processService->setId($pid);
        $this->processService->setEventManager($this->getEventManager());
        $this->processService->setConfig($this->getConfig());
        $this->processService->mainLoop();
    }

    /**
     * @param EventInterface $event
     */
    protected function addNewProcess(SchedulerEvent $event)
    {
        $pid = $event->getParam('uid');

        $this->processes[$pid] = [
            'code' => ProcessState::WAITING,
            'uid' => $pid,
            'time' => microtime(true),
            'service_name' => $this->config->getServiceName(),
            'requests_finished' => 0,
            'requests_per_second' => 0,
            'cpu_usage' => 0,
            'status_description' => '',
        ];
    }

    /**
     * Manages server processes.
     *
     * @param DisciplineInterface $discipline
     * @return $this
     */
    protected function manageProcesses(DisciplineInterface $discipline)
    {
        $operations = $discipline->manage($this->config, $this->processes);

        $toTerminate = $operations['terminate'];
        $toSoftTerminate = $operations['soft_terminate'];
        $toCreate = $operations['create'];

        $this->createProcesses($toCreate);
        $this->terminateProcesses($toTerminate, false);
        $this->terminateProcesses($toSoftTerminate, true);
    }

    /**
     * @param int[] $processIds
     * @param $isSoftTermination
     * @return $this
     */
    protected function terminateProcesses(array $processIds, $isSoftTermination)
    {
        $now = microtime(true);

        foreach ($processIds as $processId) {
            $processStatus = $this->processes[$processId];
            $processStatus['code'] = ProcessState::TERMINATED;
            $processStatus['time'] = $now;
            $this->processes[$processId] = $processStatus;

            $this->log(\Zend\Log\Logger::DEBUG, sprintf('Terminating process %d', $processId));
            $event = $this->event;
            $event->setName(SchedulerEvent::EVENT_PROCESS_TERMINATE);
            $event->setParams($this->getEventExtraData(['uid' => $processId, 'soft' => $isSoftTermination]));
            $this->events->triggerEvent($event);
        }

        return $this;
    }

    /**
     * Creates main (infinite) loop.
     *
     * @return $this
     */
    protected function mainLoop()
    {
        while ($this->isContinueMainLoop()) {
            $event = $this->event;
            $event->setName(SchedulerEvent::EVENT_SCHEDULER_LOOP);
            $event->setParams($this->getEventExtraData());
            $this->events->triggerEvent($event);
        }

        return $this;
    }

    /**
     * Handles messages.
     *
     * @return $this
     */
    protected function handleMessages()
    {
        $this->schedulerStatus->updateStatus();

        /** @var Message[] $messages */
        $this->ipcAdapter->useChannelNumber(0);

        $messages = $this->ipcAdapter->receiveAll();
        $this->setCurrentTime(microtime(true));

        foreach ($messages as $message) {
            switch ($message['type']) {
                case Message::IS_STATUS:
                    $details = $message['extra'];
                    $pid = $details['uid'];

                    /** @var ProcessState $processStatus */
                    $processStatus = $message['extra']['status'];
                    $processStatus['time'] = $this->getCurrentTime();

                    if ($processStatus['code'] === ProcessState::RUNNING) {
                        $this->schedulerStatus->incrementNumberOfFinishedTasks();
                    }

                    // child status changed, update this information server-side
                    if (isset($this->processes[$pid])) {
                        $this->processes[$pid] = $processStatus;
                    }

                    break;

                case Message::IS_STATUS_REQUEST:
                    $this->logger->debug('Status request detected');
                    $this->sendSchedulerStatus($this->ipcAdapter);
                    break;

                default:
                    $this->logMessage($message);
                    break;
            }
        }

        return $this;
    }

    private function sendSchedulerStatus(IpcAdapterInterface $ipcAdapter)
    {
        $payload = [
            'isEvent' => false,
            'type' => Message::IS_STATUS,
            'priority' => '',
            'message' => 'statusSent',
            'extra' => [
                'uid' => $this->getId(),
                'logger' => __CLASS__,
                'process_status' => $this->processes->toArray(),
                'scheduler_status' => $this->schedulerStatus->toArray(),
            ]
        ];

        $payload['extra']['scheduler_status']['total_traffic'] = 0;
        $payload['extra']['scheduler_status']['start_timestamp'] = $this->startTime;

        $ipcAdapter->send($payload);
    }
}