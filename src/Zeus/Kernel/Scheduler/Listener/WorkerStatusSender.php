<?php

namespace Zeus\Kernel\Scheduler\Listener;

use Throwable;
use Zend\Log\LoggerInterface;
use Zeus\Kernel\IpcServer;
use Zeus\Kernel\Scheduler\Status\StatusMessage;
use Zeus\Kernel\Scheduler\Status\WorkerState;
use Zeus\Kernel\Scheduler\WorkerEvent;
use Zeus\ServerService\Shared\Logger\ExceptionLoggerTrait;

class WorkerStatusSender
{
    use ExceptionLoggerTrait;

    /**
     * @var LoggerInterface
     */
    private $logger;
    /**
     * @var IpcServer
     */
    private $ipc;

    public function __construct(LoggerInterface $logger, IpcServer $ipc)
    {
        $this->logger = $logger;
        $this->ipc = $ipc;
    }

    public function __invoke(WorkerEvent $event)
    {
        $worker = $event->getWorker();
        $worker->updateStatus();

        $message = new StatusMessage($worker->toArray());

        try {
            $this->ipc->send($message, IpcServer::AUDIENCE_SERVER);
        } catch (Throwable $exception) {
            $this->logException($exception, $this->logger);
            $worker->setCode(WorkerState::EXITING);
            $event->setParam('exception', $exception);
        }
    }
}