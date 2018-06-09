<?php

namespace Zeus\Kernel\Scheduler\MultiProcessingModule\Listener;

use Zeus\Kernel\Scheduler\WorkerEvent;
use Zeus\Kernel\System\Runtime;

class WorkerExitListener extends AbstractListener
{
    public function __invoke(WorkerEvent $event)
    {
        $this->driver->onWorkerExit($event);

        if (!$event->propagationIsStopped()) {
            /** @var \Exception $exception */
            $exception = $event->getParam('exception');

            $status = $exception ? $exception->getCode() : 0;
            Runtime::exit($status);
        }
    }
}