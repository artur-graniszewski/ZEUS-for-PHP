<?php

namespace Zeus\Kernel\ProcessManager;

use Zend\EventManager\EventManagerInterface;
use Zend\EventManager\EventsCapableInterface;
use Zend\Log\Logger;
use Zeus\Kernel\IpcServer\Adapter\IpcAdapterInterface;
use Zeus\Kernel\IpcServer\IpcEvent;
use Zeus\Kernel\ProcessManager\Exception\ProcessManagerException;
use Zeus\Kernel\ProcessManager\Helper\PluginRegistry;
use Zeus\Kernel\ProcessManager\MultiProcessingModule\MultiProcessingModuleInterface;
use Zeus\Kernel\ProcessManager\Scheduler\Discipline\DisciplineInterface;
use Zeus\Kernel\ProcessManager\Scheduler\ProcessCollection;
use Zeus\Kernel\ProcessManager\Status\ProcessState;
use Zeus\Kernel\IpcServer\Message;

/**
 * Class Scheduler
 * @package Zeus\Kernel\ProcessManager
 * @internal
 */
final class Scheduler extends AbstractProcess implements EventsCapableInterface, ProcessInterface
{
    use PluginRegistry;

    /** @var ProcessState[]|ProcessCollection */
    protected $processes = [];

    /** @var Config */
    protected $config;

    /** @var bool */
    protected $schedulerActive = true;

    /** @var ProcessState */
    protected $schedulerStatus;

    /** @var Process */
    protected $processService;

    /** @var IpcAdapterInterface */
    protected $ipc;

    protected $discipline;

    /** @var mixed[] */
    protected $eventHandles;

    /** @var MultiProcessingModuleInterface */
    protected $multiProcessingModule;

    /**
     * @return bool
     */
    public function isSchedulerActive()
    {
        return $this->schedulerActive;
    }

    /**
     * @return Process
     */
    public function getProcessService()
    {
        return $this->processService;
    }

    /**
     * @param bool $schedulerActive
     * @return $this
     */
    public function setSchedulerActive($schedulerActive)
    {
        $this->schedulerActive = $schedulerActive;

        return $this;
    }

    /**
     * Scheduler constructor.
     * @param ConfigInterface $config
     * @param Process $processService
     * @param IpcAdapterInterface $ipcAdapter
     * @param DisciplineInterface $discipline
     */
    public function __construct(ConfigInterface $config, Process $processService, IpcAdapterInterface $ipcAdapter, DisciplineInterface $discipline)
    {
        $this->discipline = $discipline;
        $this->config = $config;
        $this->ipc = $ipcAdapter;
        $this->processService = $processService;
        $this->status = new ProcessState($this->getConfig()->getServiceName());

        $this->processes = new ProcessCollection($this->getConfig()->getMaxProcesses());
    }

    public function __destruct()
    {
        foreach ($this->getPluginRegistry() as $plugin) {
            $this->removePlugin($plugin);
        }

//        if ($this->eventHandles) {
//            $events = $this->getEventManager();
//            foreach ($this->eventHandles as $handle) {
//                $events->detach($handle);
//            }
//        }
    }

    /**
     * @param EventManagerInterface $events
     * @return $this
     */
    public function attach(EventManagerInterface $events)
    {
        $events = $events->getSharedManager();
        $this->eventHandles[] = $events->attach('*', SchedulerEvent::EVENT_PROCESS_CREATE, function(SchedulerEvent $e) { $this->addNewProcess($e);}, SchedulerEvent::PRIORITY_FINALIZE);
        $this->eventHandles[] = $events->attach('*', SchedulerEvent::EVENT_PROCESS_CREATE, function(SchedulerEvent $e) { $this->onProcessCreate($e);}, SchedulerEvent::PRIORITY_FINALIZE + 1);
        $this->eventHandles[] = $events->attach('*', SchedulerEvent::EVENT_PROCESS_TERMINATED, function(SchedulerEvent $e) { $this->onProcessTerminated($e);}, SchedulerEvent::PRIORITY_FINALIZE);
        $this->eventHandles[] = $events->attach('*', ProcessEvent::EVENT_PROCESS_EXIT, function(ProcessEvent $e) { $this->onProcessExit($e); }, SchedulerEvent::PRIORITY_FINALIZE);
        $this->eventHandles[] = $events->attach('*', ProcessEvent::EVENT_PROCESS_MESSAGE, function(IpcEvent $e) { $this->onProcessMessage($e);});
        $this->eventHandles[] = $events->attach('*', SchedulerEvent::EVENT_SCHEDULER_STOP, function(SchedulerEvent $e) { $this->onShutdown($e);}, SchedulerEvent::PRIORITY_REGULAR);
        //$this->eventHandles[] = $events->attach(SchedulerEvent::EVENT_SCHEDULER_STOP, function(SchedulerEvent $e) { $this->onProcessExit($e); }, SchedulerEvent::PRIORITY_FINALIZE);
        $this->eventHandles[] = $events->attach('*', SchedulerEvent::EVENT_SCHEDULER_START, function() { $this->onSchedulerStart(); }, SchedulerEvent::PRIORITY_FINALIZE);

        $this->eventHandles[] = $events->attach('*', SchedulerEvent::EVENT_SCHEDULER_LOOP, function() {
            $this->collectCycles();
            $this->manageProcesses($this->discipline);
        });

        return $this;
    }

    /**
     * @param IpcEvent $event
     */
    protected function onProcessMessage(IpcEvent $event)
    {
        $message = $event->getParams();

        switch ($message['type']) {
            case Message::IS_STATUS:
                $details = $message['extra'];
                $pid = $details['uid'];
                $threadId = $details['threadId'];

                if ($threadId > 1) {
                    $pid = $threadId;
                }

                /** @var ProcessState $processStatus */
                $processStatus = $message['extra']['status'];

                // process status changed, update this information server-side
                if (isset($this->processes[$pid])) {
                    if ($this->processes[$pid]['code'] !== $processStatus['code']) {
                        $processStatus['time'] = microtime(true);
                    }

                    $this->processes[$pid] = $processStatus;
                }

                break;

            default:
                $this->logMessage($message);
                break;
        }
    }

    /**
     * Logs server messages.
     *
     * @param mixed[] $message
     * @return $this
     */
    protected function logMessage($message)
    {
        $extra = $message['extra'];
        $extra['service_name'] = sprintf("%s-%d", $this->getConfig()->getServiceName(), $extra['uid']);
        $this->log($message['priority'], $message['message'], $extra);

        return $this;
    }

    /**
     * @param int $priority
     * @param string $message
     * @param mixed[] $extra
     * @return $this
     */
    protected function log($priority, $message, $extra = [])
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
     * @param ProcessEvent $event
     */
    protected function onProcessExit(ProcessEvent $event)
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
        $this->log(Logger::DEBUG, "Process $pid exited");

        if (isset($this->processes[$pid])) {
            $processStatus = $this->processes[$pid];

            if (!ProcessState::isExiting($processStatus) && $processStatus['time'] < microtime(true) - $this->getConfig()->getProcessIdleTimeout()) {
                $this->log(Logger::ERR, "Process $pid exited prematurely");
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

        $pid = @file_get_contents($fileName);
        if (!$pid) {
            throw new ProcessManagerException("Server not running: " . $fileName, ProcessManagerException::SERVER_NOT_RUNNING);
        }

        $pid = (int) $pid;

        $this->stopProcess($pid, true);
        $this->log(Logger::INFO, "Server stopped");
        unlink($fileName);

        return $this;
    }

    /**
     * @return string
     */
    public function getPidFile()
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
    protected function triggerEvent($eventName, $extraData = [])
    {
        $extraData = array_merge($this->status->toArray(), $extraData, ['service_name' => $this->getConfig()->getServiceName()]);
        $events = $this->getEventManager();
        $event = new SchedulerEvent();
        $event->setTarget($this);
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
    public function start($launchAsDaemon = null)
    {
        $this->getMultiProcessingModule()->attach($this->getEventManager());
        $plugins = $this->getPluginRegistry()->count();
        $this->log(Logger::INFO, sprintf("Starting Scheduler with %d plugin%s", $plugins, $plugins !== 1 ? 's' : ''));
        $this->collectCycles();

        $events = $this->getEventManager();
        $this->attach($events);
        $events = $events->getSharedManager();
        $this->log(Logger::INFO, "Establishing IPC");
        if (!$this->getIpc()->isConnected()) {
            $this->getIpc()->connect();
        }

        try {
            if (!$launchAsDaemon) {
                $this->setProcessId(getmypid());
                $this->triggerEvent(SchedulerEvent::INTERNAL_EVENT_KERNEL_START);
                $this->triggerEvent(SchedulerEvent::EVENT_SCHEDULER_START);
                $this->kernelLoop();

                return $this;
            }

            $this->eventHandles[] = $events->attach('*', SchedulerEvent::EVENT_PROCESS_CREATE,
                function(SchedulerEvent $e) {
                    if (!$e->getParam('server')) {
                        return;
                    }

                    if ($e->getParam('init_process')) {
                        $e->stopPropagation(true);
                        $this->setProcessId(getmypid());
                        $this->triggerEvent(SchedulerEvent::EVENT_SCHEDULER_START);
                    } else {
                        $this->triggerEvent(SchedulerEvent::INTERNAL_EVENT_KERNEL_START);
                    }
                }, SchedulerEvent::PRIORITY_FINALIZE);

            $this->eventHandles[] = $events->attach('*', SchedulerEvent::EVENT_PROCESS_CREATE,
                function (SchedulerEvent $event) {
                    if (!$event->getParam('server') || $event->getParam('init_process')) {
                        return;
                    }

                    $pid = $event->getParam('uid');
                    $this->setProcessId($pid);

                    $fileName = sprintf("%s%s.pid", $this->getConfig()->getIpcDirectory(), $this->getConfig()->getServiceName());
                    if (!@file_put_contents($fileName, $pid)) {
                        throw new ProcessManagerException(sprintf("Could not write to PID file: %s, aborting", $fileName), ProcessManagerException::LOCK_FILE_ERROR);
                    }

                    $this->kernelLoop();
                }
                , SchedulerEvent::PRIORITY_FINALIZE
            );

            $this->processService->start(['server' => true]);

        } catch (\Throwable $exception) {
            $this->handleException($exception);
        }

        return $this;
    }

    /**
     * @param \Throwable|\Exception $exception
     * @return $this
     */
    protected function handleException($exception)
    {
        $this->triggerEvent(SchedulerEvent::EVENT_SCHEDULER_STOP, ['exception' => $exception]);

        return $this;
    }

    protected function onSchedulerStart()
    {
        $this->log(Logger::INFO, "Scheduler started");
        $this->createProcesses($this->getConfig()->getStartProcesses());

        $this->mainLoop();
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
     * @param int $uid
     * @param bool $isSoftStop
     * @return $this
     */
    protected function stopProcess($uid, $isSoftStop)
    {
        $this->triggerEvent(SchedulerEvent::EVENT_PROCESS_TERMINATE, ['uid' => $uid, 'soft' => $isSoftStop]);

        return $this;
    }

    /**
     * Shutdowns the server
     *
     * @param SchedulerEvent $event
     */
    protected function onShutdown(SchedulerEvent $event)
    {
        if (!$this->isSchedulerActive()) {
            return;
        }

        $exception = $event->getParam('exception', null);

        $this->log(Logger::DEBUG, "Shutting down" . ($exception ? ' with exception: ' . $exception->getMessage() : ''));

        $this->setSchedulerActive(false);

        $this->log(Logger::INFO, "Terminating scheduler");

        if ($this->processes) {
            foreach (array_keys($this->processes->toArray()) as $pid) {
                $this->log(Logger::DEBUG, "Terminating process $pid");
                $this->stopProcess($pid, false);
            }
        }

        if ($exception) {
            $status = $exception->getCode();
            $this->log(Logger::ERR, sprintf("Exception (%d): %s in %s:%d", $status, $exception->getMessage(), $exception->getFile(), $exception->getLine()));
        }

        $this->log(Logger::INFO, "Scheduler terminated");

        $this->log(Logger::INFO, "Stopping IPC");
        $this->getIpc()->disconnect();
    }

    /**
     * Create processes
     *
     * @param int $count Number of processes to create.
     * @return $this
     */
    protected function createProcesses($count)
    {
        if ($count === 0) {
            return $this;
        }

        for ($i = 0; $i < $count; ++$i) {
            $this->processService->start();
        }

        return $this;
    }

    /**
     * @param SchedulerEvent $event
     */
    protected function onProcessCreate(SchedulerEvent $event)
    {
        if (!$event->getParam('init_process') || $event->getParam('server')) {
            return;
        }

        $process = $this->processService;
        $process->setProcessId($event->getParam('uid'));
        $process->setThreadId($event->getParam('threadId'));
        $this->collectCycles();
        $this->setSchedulerActive(false);
        $this->getIpc()->useChannelNumber(1);
    }

    /**
     * @param SchedulerEvent $event
     */
    protected function addNewProcess(SchedulerEvent $event)
    {
        if ($event->getParam('server')) {
            return;
        }

        $pid = $event->getParam('uid');

        $this->processes[$pid] = [
            'code' => ProcessState::WAITING,
            'uid' => $pid,
            'time' => microtime(true),
            'service_name' => $this->getConfig()->getServiceName(),
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
        $operations = $discipline->manage($this->getConfig(), $this->processes);

        $toTerminate = $operations['terminate'];
        $toSoftTerminate = $operations['soft_terminate'];
        $toCreate = $operations['create'];

        $this->createProcesses($toCreate);
        $this->terminateProcesses($toTerminate, false);
        $this->terminateProcesses($toSoftTerminate, true);

        return $this;
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

            $this->log(Logger::DEBUG, sprintf('Terminating process %d', $processId));
            $this->stopProcess($processId, $isSoftTermination);
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
        do {
            $this->triggerEvent(SchedulerEvent::EVENT_SCHEDULER_LOOP);

            if (!$this->isSchedulerActive()) {
                $this->triggerEvent(SchedulerEvent::EVENT_SCHEDULER_STOP);
            }
        } while ($this->isSchedulerActive());

        return $this;
    }

    /**
     * @return $this
     */
    public function kernelLoop()
    {
        while ($this->isSchedulerActive()) {
            $this->triggerEvent(SchedulerEvent::EVENT_KERNEL_LOOP);

            usleep(1000);
        }

        return $this;
    }

    /**
     * @return ProcessCollection|Status\ProcessState[]
     */
    public function getProcesses()
    {
        return $this->processes;
    }

    /**
     * @param MultiProcessingModuleInterface $driver
     * @return $this
     */
    public function setMultiProcessingModule(MultiProcessingModuleInterface $driver)
    {
        $this->multiProcessingModule = $driver;

        return $this;
    }

    /**
     * @return MultiProcessingModuleInterface
     */
    public function getMultiProcessingModule()
    {
        return $this->multiProcessingModule;
    }
}