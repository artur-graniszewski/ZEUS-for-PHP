<?php

namespace Zeus\Kernel\ProcessManager;

use Zend\EventManager\EventInterface;
use Zend\EventManager\EventManagerInterface;
use Zend\Log\LoggerInterface;
use Zeus\Kernel\IpcServer\Adapter\IpcAdapterInterface;
use Zeus\Kernel\ProcessManager\Exception\ProcessManagerException;
use Zeus\Kernel\ProcessManager\Helper\Logger;
use Zeus\Kernel\ProcessManager\Scheduler\EventsInterface;
use Zeus\Kernel\ProcessManager\Scheduler\ProcessCollection;
use Zeus\Kernel\ProcessManager\Status\ProcessState;
use Zeus\Kernel\IpcServer\Message;
use Zeus\Kernel\ProcessManager\Status\ProcessTitle;
use Zeus\Kernel\ProcessManager\Helper\EventManager;

final class Scheduler
{
    use Logger;
    use EventManager;

    /** @var ProcessState[]|ProcessCollection */
    protected $processes = [];

    /** @var Config */
    protected $config;

    /** @var float */
    protected $time;

    /** @var bool */
    protected $continueMainLoop = true;

    /** @var int */
    protected $id;

    /** @var ProcessState */
    protected $status;

    /** @var Process */
    protected $processTemplate;

    /** @var IpcAdapterInterface */
    protected $ipcAdapter;

    /** @var ProcessTitle */
    protected $processTitle;

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
        return $this->id;
    }

    /**
     * @param int $id
     * @return $this
     */
    public function setId($id)
    {
        $this->id = $id;

        return $this;
    }

    /**
     * @return float
     */
    public function getTime()
    {
        return $this->time;
    }

    /**
     * @param float $time
     * @return $this
     */
    public function setTime($time)
    {
        $this->time = $time;

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
     * Handles input arguments.
     *
     * @param mixed[] $config
     * @param Process $processTemplate
     * @param LoggerInterface $logger
     * @param IpcAdapterInterface $ipcAdapter
     */
    public function __construct($config, Process $processTemplate, LoggerInterface $logger, IpcAdapterInterface $ipcAdapter)
    {
        $this->config = new Config($config);
        $this->ipcAdapter = $ipcAdapter;
        $this->processTemplate = $processTemplate;
        $this->status = new ProcessState($this->config->getServiceName());

        $this->processes = new ProcessCollection($this->config->getMaxProcesses() + 1);
        $this->setLogger($logger);
        $this->setLoggerExtraDetails(['service' => $this->config->getServiceName()]);

        if (!defined('STDIN')) {
            throw new ProcessManagerException("This application must be launched from the Command Line Interpreter", ProcessManagerException::CLI_MODE_REQUIRED);
        }

        $this->processTitle = new ProcessTitle();
        $this->processTitle->attach($this->getEventManager());
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
        $events->detach([$this, 'startScheduler']);
        $events->attach(EventsInterface::ON_PROCESS_CREATE, function(EventInterface $e) { $this->addNewProcess($e);}, -10000);
        $events->attach(EventsInterface::ON_PROCESS_INIT, function(EventInterface $e) { $this->onProcessInit($e);});
        $events->attach(EventsInterface::ON_PROCESS_TERMINATED, function(EventInterface $e) { $this->onProcessExit($e);}, -10000);
        $events->attach(EventsInterface::ON_PROCESS_TERMINATE, function(EventInterface $e) { $this->onProcessExit($e);}, -10000);
        $events->attach(EventsInterface::ON_PROCESS_MESSAGE, function(EventInterface $e) { $this->onProcessMessage($e);});
        $events->attach(EventsInterface::ON_SERVER_STOP, function(EventInterface $e) { $this->onShutdown($e);});

        $events->attach(EventsInterface::ON_SCHEDULER_LOOP, function(EventInterface $e) {
            $this->collectCycles();
            $this->handleMessages();
            $this->manageProcesses();
        });

        return $this;
    }

    /**
     * @param EventInterface $event
     */
    protected function onShutdown(EventInterface $event)
    {
        $this->shutdown();
    }

    /**
     * @param EventInterface $event
     */
    protected function onProcessMessage(EventInterface $event)
    {
        $this->ipcAdapter->send($event->getParams());
    }

    /**
     * @param EventInterface $event
     */
    protected function onProcessExit(EventInterface $event)
    {
        if ($event->getParam('uid') === $this->getId()) {
            $this->shutdown();

            return;
        }

        if ($this->isContinueMainLoop()) {
            $this->manageProcesses();
        }

        $this->terminateProcess($event->getParam('uid'));
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
                $this->events->trigger(EventsInterface::ON_PROCESS_TERMINATE, $this,
                    $this->getEventExtraData([
                        'uid' => $pid,
                        'soft' => true,
                    ]
                    ));
                $this->log(\Zend\Log\Logger::INFO, "Server stopped");
                unlink($fileName);

                return $this;
            }
        }

        throw new ProcessManagerException("Server not running", ProcessManagerException::SERVER_NOT_RUNNING);
    }

    /**
     * @param mixed[] $extraExtraData
     * @return mixed[]
     */
    private function getEventExtraData($extraExtraData = [])
    {
        $extraExtraData = array_merge($this->status->toArray(), $extraExtraData, ['serviceName' => $this->config->getServiceName()]);
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
        $this->log(\Zend\Log\Logger::INFO, "Starting server");
        $this->collectCycles();

        $events = $this->getEventManager();

        try {
            if (!$launchAsDaemon) {
                $events->attach(EventsInterface::ON_SERVER_START, [$this, 'startScheduler'], 100000);
                $this->getEventManager()->trigger(EventsInterface::ON_SERVER_START, $this, $this->getEventExtraData());

                return $this;
            }

            $events->attach(EventsInterface::ON_PROCESS_INIT, [$this, 'startScheduler'], 100000);
            $events->attach(EventsInterface::ON_PROCESS_CREATE,
                function (EventInterface $event) {
                    $pid = $event->getParam('uid');

                    if (!@file_put_contents(sprintf("%s%s.pid", $this->getConfig()->getIpcDirectory(), $this->config->getServiceName()), $pid)) {
                        throw new ProcessManagerException("Could not write to PID file, aborting", ProcessManagerException::LOCK_FILE_ERROR);
                    }
                }
                , -10000
            );

            $this->getEventManager()->trigger(EventsInterface::ON_PROCESS_CREATE, $this, $this->getEventExtraData(['server' => true]));
            $this->getEventManager()->trigger(EventsInterface::ON_SERVER_START, $this, $this->getEventExtraData());
        } catch (\Throwable $e) {
            $this->shutdown($e);
        } catch (\Exception $e) {
            $this->shutdown($e);
        }

        return $this;
    }

    /**
     * @param EventInterface $event
     * @return $this
     */
    public function startScheduler(EventInterface $event)
    {
        $this->log(\Zend\Log\Logger::DEBUG, "Scheduler starting...");

        $this->setId(getmypid());

        $this->attach($this->getEventManager());

        $this->getEventManager()->trigger(EventsInterface::ON_SCHEDULER_START, $this, $this->getEventExtraData());

        $config = $this->getConfig();

        if ($config->isProcessCacheEnabled()) {
            $this->createProcesses($config->getStartProcesses());
        }

        $this->log(\Zend\Log\Logger::INFO, "Scheduler started");
        $event->stopPropagation(true);

        return $this->mainLoop();
    }

    /**
     * @param string $pid
     * @return $this
     */
    public function terminateProcess($pid)
    {
        $this->log(\Zend\Log\Logger::DEBUG, "Process $pid exited");

        if (isset($this->processes[$pid])) {
            $processStatus = $this->processes[$pid];

            if (!ProcessState::isExiting($processStatus) && $processStatus['time'] < microtime(true) - $this->getConfig()->getProcessIdleTimeout()) {
                $this->log(\Zend\Log\Logger::ERR, "Process $pid exited prematurely");
            }

            unset($this->processes[$pid]);
        }

        return $this;
    }

    /**
     * @return $this
     */
    protected function collectCycles()
    {
        $enabled = gc_enabled();
        gc_enable();
        if (function_exists('gc_mem_caches')) {
            gc_mem_caches();
        }
        gc_collect_cycles();

        if (!$enabled) {
            gc_disable();
        }

        return $this;
    }

    /**
     * Shutdowns the server
     *
     * @param \Exception|\Throwable $exception
     * @return $this
     */
    protected function shutdown($exception = null)
    {
        $status = 0;
        $this->setContinueMainLoop(false);

        $this->log(\Zend\Log\Logger::INFO, "Terminating scheduler");

        $processes = count($this->processes);

        foreach ($this->processes as $pid => $value) {
            if (!$pid) {
                continue;
            }

            $this->log(\Zend\Log\Logger::DEBUG, "Terminating process $pid");
            $this->events->trigger(EventsInterface::ON_PROCESS_TERMINATE, $this, $this->getEventExtraData(['uid' => $pid]));
        }

        $this->handleMessages();

        if ($exception) {
            $status = $exception->getCode();
            $this->log(\Zend\Log\Logger::ERR, sprintf("Exception (%d): %s in %s:%d", $status, $exception->getMessage(), $exception->getFile(), $exception->getLine()));
        }

        $this->log(\Zend\Log\Logger::INFO, "Terminated scheduler with $processes processes");
        $this->getEventManager()->trigger(EventsInterface::ON_SCHEDULER_STOP, $this, $this->getEventExtraData(['exception' => $exception]));

        $this->ipcAdapter->disconnect();

        exit($status);
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

        $this->setTime(microtime(true));

        for ($i = 0; $i < $count; ++$i) {
            $this->getEventManager()->trigger(EventsInterface::ON_PROCESS_CREATE, $this, $this->getEventExtraData());
        }

        return $this;
    }

    /**
     * @param EventInterface $event
     */
    protected function onProcessInit(EventInterface $event)
    {
        $pid = $event->getParam('uid');

        unset($this->processes);
        $this->collectCycles();
        $this->setContinueMainLoop(false);
        $this->ipcAdapter->useChannelNumber(1);


        $this->processTemplate->setId($pid);
        $this->processTemplate->setEventManager($this->getEventManager());
        $this->processTemplate->setConfig($this->getConfig());
        $this->processTemplate->mainLoop();

        exit(0);
    }

    /**
     * @param EventInterface $event
     */
    protected function addNewProcess(EventInterface $event)
    {
        $pid = $event->getParam('uid');

        $this->processes[$pid] = [
            'code' => ProcessState::WAITING,
            'uid' => $pid,
            'time' => microtime(true)
        ];
    }

    /**
     * Manages server processes.
     *
     * @return $this
     */
    protected function manageProcesses()
    {
        $config = $this->getConfig();
        $this->setTime(microtime(true));

        /**
         * Time after which idle process should be ultimately terminated.
         *
         * @var float
         */
        $expireTime = $this->getTime() - $config->getProcessIdleTimeout();

        $statusSummary = $this->processes->getStatusSummary();
        $idleProcesses = $statusSummary[ProcessState::WAITING];
        $busyProcesses = $statusSummary[ProcessState::RUNNING];
        //$terminatedProcesses = $statusSummary[ProcessStatus::STATUS_EXITING] + $statusSummary[ProcessStatus::STATUS_KILL];
        $allProcesses = $this->processes->count();

        if (!$this->isContinueMainLoop() || !$config->isProcessCacheEnabled()) {
            return $this;
        }

        // start additional processes, if number of them is too small.
        if ($idleProcesses < $config->getMinSpareProcesses()) {
            $idleProcessSlots = $this->processes->getSize() - $this->processes->count();

            $processAmountToCreate = min($idleProcessSlots, $config->getMinSpareProcesses());

            if ($processAmountToCreate > 0) {
                $this->createProcesses($processAmountToCreate);
            }

            return $this;
        }

        if ($allProcesses === 0 && $config->getMinSpareProcesses() === 0 && $config->getMaxSpareProcesses() > 0) {
            $this->createProcesses($config->getMaxSpareProcesses());

            return $this;
        }

        // terminate idle processes, if number of them is too high.
        if ($idleProcesses > $config->getMaxSpareProcesses()) {
            $toTerminate = $idleProcesses - $config->getMaxSpareProcesses();
            $terminated = 0;

            foreach ($this->processes as $pid => $processStatus) {
                if (!$processStatus || !ProcessState::isIdle($processStatus)) {
                    continue;
                }

                if ($processStatus['time'] < $expireTime) {
                    $processStatus['code'] = ProcessState::TERMINATED;
                    $processStatus['time'] = $this->getTime();
                    $this->processes[$pid] = $processStatus;

                    $this->log(\Zend\Log\Logger::DEBUG, sprintf('Terminating idle process %d', $pid));
                    $this->events->trigger(EventsInterface::ON_PROCESS_TERMINATE, $this, $this->getEventExtraData(['uid' => $pid, 'soft' => true]));

                    ++$terminated;

                    if ($terminated === $toTerminate || $terminated === $config->getMaxSpareProcesses()) {
                        break;
                    }
                }
            }
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
            $this->events->trigger(EventsInterface::ON_SCHEDULER_LOOP, $this, $this->getEventExtraData());
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
        $this->status->updateStatus();

        /** @var Message[] $messages */
        $this->ipcAdapter->useChannelNumber(0);

        $messages = $this->ipcAdapter->receiveAll();
        $this->setTime(microtime(true));

        foreach ($messages as $message) {
            switch ($message['type']) {
                case Message::IS_STATUS:
                    $details = $message['extra'];
                    $pid = $details['uid'];

                    /** @var ProcessState $processStatus */
                    $processStatus = $message['message'];
                    $processStatus['time'] = $this->getTime();

                    if ($processStatus['code'] === ProcessState::RUNNING) {
                        $this->status->incrementNumberOfFinishedTasks();
                    }

                    // child status changed, update this information server-side
                    if (isset($this->processes[$pid])) {
                        $this->processes[$pid] = $processStatus;
                    }

                    break;

                default:
                    $this->logMessage($message);
                    break;
            }
        }

        return $this;
    }
}