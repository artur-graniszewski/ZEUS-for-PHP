<?php

namespace Zeus\Kernel\Scheduler\Listener;

use Throwable;
use Zeus\Kernel\IpcServer;
use Zeus\Kernel\IpcServer\Message;
use Zeus\Kernel\Scheduler\Status\StatusMessage;
use Zeus\Kernel\Scheduler\Status\WorkerState;
use Zeus\Kernel\Scheduler\WorkerEvent;
use Zeus\ServerService\Shared\Logger\ExceptionLoggerTrait;

class WorkerStatusSender
{
    use ExceptionLoggerTrait;

    public function __invoke(WorkerEvent $event)
    {
        $scheduler = $event->getScheduler();

        $worker = $event->getWorker();
        $worker->updateStatus();

        $message = new StatusMessage($worker->toArray());

        try {
            $scheduler->getIpc()->send($message, IpcServer::AUDIENCE_SERVER);
        } catch (Throwable $exception) {
            $this->logException($exception, $scheduler->getLogger());
            $worker->setCode(WorkerState::EXITING);
            $event->setParam('exception', $exception);
        }
    }
}