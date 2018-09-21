<?php

namespace Zeus\Kernel\Scheduler\Listener;

use Zeus\Kernel\IpcServer\IpcEvent;
use Zeus\Kernel\Scheduler\Shared\WorkerCollection;
use Zeus\Kernel\Scheduler\Status\StatusMessage;
use Zeus\Kernel\Scheduler\Status\WorkerState;

use function microtime;

class WorkerStatusListener
{
    /** @var WorkerCollection|WorkerState[] */
    private $workers;

    public function __construct(WorkerCollection $workers)
    {
        $this->workers = $workers;
    }

    public function __invoke(IpcEvent $event)
    {
        $message = $event->getParams();

        if (!($message instanceof StatusMessage)) {
            return;
        }

        $message = $message->getParams();

        /** @var WorkerState $workerState */
        $workerState = WorkerState::fromArray($message);
        $uid = $workerState->getUid();

        // worker status changed, update this information server-side
        if (isset($this->workers[$uid])) {
            if ($this->workers[$uid]->getCode() !== $workerState->getCode()) {
                $workerState->setTime(microtime(true));
            }

            $this->workers[$uid] = $workerState;
        }
    }
}