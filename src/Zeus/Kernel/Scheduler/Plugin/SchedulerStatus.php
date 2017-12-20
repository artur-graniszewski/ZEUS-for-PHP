<?php

namespace Zeus\Kernel\Scheduler\Plugin;

use Zend\EventManager\EventManagerInterface;
use Zend\EventManager\ListenerAggregateInterface;
use Zeus\Kernel\IpcServer;
use Zeus\Kernel\IpcServer\IpcDriver;
use Zeus\Kernel\IpcServer\IpcEvent;
use Zeus\Kernel\IpcServer\Message;
use Zeus\Kernel\Scheduler;
use Zeus\Kernel\Scheduler\SchedulerEvent;
use Zeus\Kernel\Scheduler\Status\WorkerState;

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
        $this->schedulerStatus = new WorkerState($event->getTarget()->getConfig()->getServiceName());
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
        $this->eventHandles[] = $events->attach('*', SchedulerEvent::EVENT_START, function(SchedulerEvent $e) use ($events, $priority) {
            $this->init($e);
            $this->eventHandles[] = $events->attach('*', IpcEvent::EVENT_MESSAGE_RECEIVED, function(IpcEvent $e) { $this->onProcessMessage($e);}, $priority);
        }, $priority);
        $this->eventHandles[] = $events->attach('*', SchedulerEvent::EVENT_LOOP, function(SchedulerEvent $e) { $this->onSchedulerLoop();}, $priority);
    }

    protected function onProcessMessage(IpcEvent $event)
    {
        $message = $event->getParams();

        if (!is_array($message)) {
            return;
        }

        switch ($message['type']) {
            case Message::IS_STATUS_REQUEST:
                $this->refreshStatus = true;
                $event->stopPropagation(true);
                break;

            case Message::IS_STATUS:
                if ($message['extra']['status']['code'] === WorkerState::RUNNING) {
                    $this->scheduler->getStatus()->incrementNumberOfFinishedTasks();
                }
                break;
        }
    }

    /**
     * @param Scheduler $scheduler
     * @return mixed[]
     */
    public static function getStatus(Scheduler $scheduler)
    {
        $ipc = $scheduler->getIpc();

        $payload = [
            'type' => Message::IS_STATUS_REQUEST,
            'message' => 'fetchStatus',
            'extra' => [
                'uid' => getmypid(),
                'logger' => __CLASS__
            ]
        ];

        $ipc->send($payload, IpcServer::AUDIENCE_SERVER);

        $timeout = 5;
        $result = null;
        do {
            $result = $ipc->receive(1);
            usleep(1000);
            $timeout--;
        } while (!$result && $timeout >= 0);

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
            'type' => Message::IS_STATUS,
            'message' => 'statusSent',
            'extra' => [
                'uid' => getmypid(),
                'logger' => __CLASS__,
                'process_status' => $scheduler->getWorkers()->toArray(),
                'scheduler_status' => $scheduler->getStatus()->toArray(),
            ]
        ];

        $payload['extra']['scheduler_status']['total_traffic'] = 0;
        $payload['extra']['scheduler_status']['start_timestamp'] = $this->startTime;

        $scheduler->getSchedulerIpc()->send(1, $payload);
        $this->refreshStatus = false;
    }
}