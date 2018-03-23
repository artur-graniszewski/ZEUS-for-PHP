<?php

namespace Zeus\Kernel\Scheduler;

use Zeus\Kernel\Scheduler\Status\WorkerState;
use Zeus\ServerService\Shared\Logger\ExceptionLoggerTrait;

use function time;

/**
 * @internal
 */
final class Worker extends AbstractService
{
    use ExceptionLoggerTrait;

    /** @var WorkerState */
    private $status;

    public function getStatus() : WorkerState
    {
        if (!$this->status) {
            $this->status = new WorkerState($this->getConfig()->getServiceName());
        }

        return $this->status;
    }

    private function updateStatus(string $statusDescription = '', int $statusCode) : bool
    {
        $status = $this->getStatus();
        $now = time();
        if ($status->getCode() === $statusCode) {
            if ($statusDescription === $status->getStatusDescription() && $status->getTime() === $now) {
                return false;
            }
        }

        $status->setTime($now);
        $status->setCode($statusCode);
        $status->setStatusDescription($statusDescription);

        return true;
    }

    private function triggerStatusChange()
    {
        $status = $this->getStatus();
        $params = [
            'status' => $status
        ];

        $this->triggerWorkerEvent($status->getCode() === WorkerState::RUNNING ? WorkerEvent::EVENT_RUNNING : WorkerEvent::EVENT_WAITING, $params);
    }

    public function setRunning(string $statusDescription = '')
    {
        if ($this->updateStatus($statusDescription, WorkerState::RUNNING)) {
            $this->triggerStatusChange();
        }
    }

    public function setWaiting(string $statusDescription = '')
    {
        if ($this->updateStatus($statusDescription, WorkerState::WAITING)) {
            $this->triggerStatusChange();
        }
    }

    private function triggerWorkerEvent(string $eventName, array $params)
    {
        $event = new WorkerEvent();
        $event->setTarget($this);
        $event->setWorker($this);
        $event->setName($eventName);
        $event->setParams($params);
        $this->getEventManager()->triggerEvent($event);
    }
}