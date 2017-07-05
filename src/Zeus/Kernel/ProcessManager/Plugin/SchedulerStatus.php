<?php

namespace Zeus\Kernel\ProcessManager\Plugin;

use Zend\EventManager\EventManagerInterface;
use Zend\EventManager\ListenerAggregateInterface;
use Zeus\Kernel\IpcServer\IpcEvent;
use Zeus\Kernel\IpcServer\Message;
use Zeus\Kernel\ProcessManager\Scheduler;
use Zeus\Kernel\ProcessManager\SchedulerEvent;
use Zeus\Kernel\ProcessManager\Status\ProcessState;

class SchedulerStatus implements ListenerAggregateInterface
{
    /** @var mixed[] */
    protected $eventHandles = [];

    protected $refreshStatus = false;

    /** @var Scheduler */
    protected $scheduler;

    /** @var float */
    protected $startTime;
    protected $schedulerStatus;

    protected function init(SchedulerEvent $event)
    {
        $this->schedulerStatus = new ProcessState($event->getTarget()->getConfig()->getServiceName());
        $this->startTime = microtime(true);
        $this->scheduler = $event->getTarget();
    }

    /**
     * @param EventManagerInterface $events
     * @param int $priority
     */
    public function attach(EventManagerInterface $events, $priority = 100)
    {
        $events = $events->getSharedManager();
        $this->eventHandles[] = $events->attach('*', SchedulerEvent::EVENT_SCHEDULER_START, function(SchedulerEvent $e) { $this->init($e);}, $priority);
        $this->eventHandles[] = $events->attach('*', SchedulerEvent::EVENT_SCHEDULER_LOOP, function(SchedulerEvent $e) { $this->onSchedulerLoop();}, $priority);
        $this->eventHandles[] = $events->attach('*', SchedulerEvent::EVENT_PROCESS_MESSAGE, function(IpcEvent $e) { $this->onProcessMessage($e);}, $priority);
    }

    protected function onProcessMessage(IpcEvent $event)
    {
        $message = $event->getParams();

        switch ($message['type']) {
            case Message::IS_STATUS_REQUEST:
                $this->refreshStatus = true;
                $event->stopPropagation(true);
                break;

            case Message::IS_STATUS:
                if ($message['extra']['status']['code'] === ProcessState::RUNNING) {
                    $this->scheduler->getStatus()->incrementNumberOfFinishedTasks();
                }
                break;
        }
    }

    /**
     * @return mixed[]
     */
    public static function getStatus(Scheduler $scheduler)
    {
        $ipc = $scheduler->getIpc();

        $payload = [
            'isEvent' => false,
            'type' => Message::IS_STATUS_REQUEST,
            'priority' => '',
            'message' => 'fetchStatus',
            'extra' => [
                'uid' => $scheduler->getProcessId(),
                'logger' => __CLASS__
            ]
        ];

        if (!$ipc->isConnected()) {
            $ipc->connect();
        }
        $ipc->useChannelNumber(1);
        $ipc->send($payload);

        $timeout = 5;
        $result = null;
        do {
            $result = $ipc->receive();
            usleep(1000);
            $timeout--;
        } while (!$result && $timeout >= 0);

        $ipc->useChannelNumber(0);

        if ($result) {
            return $result['extra'];
        }

        return null;
    }

    /**
     * Detach all previously attached listeners
     *
     * @param EventManagerInterface $events
     * @return void
     */
    public function detach(EventManagerInterface $events)
    {
        foreach ($this->eventHandles as $handle) {
            $events->detach($handle);
        }
    }

    private function onSchedulerLoop()
    {
        $scheduler = $this->scheduler;

        if (!$this->refreshStatus) {
            return;
        }

        $payload = [
            'isEvent' => false,
            'type' => Message::IS_STATUS,
            'priority' => Message::IS_STATUS,
            'message' => 'statusSent',
            'extra' => [
                'uid' => $scheduler->getProcessId(),
                'logger' => __CLASS__,
                'process_status' => $scheduler->getProcesses()->toArray(),
                'scheduler_status' => $scheduler->getStatus()->toArray(),
            ]
        ];

        $payload['extra']['scheduler_status']['total_traffic'] = 0;
        $payload['extra']['scheduler_status']['start_timestamp'] = $this->startTime;

        $scheduler->getIpc()->send($payload);
        $this->refreshStatus = false;
    }
}