<?php

namespace Zeus\Kernel\Scheduler\MultiProcessingModule\Listener;

use Throwable;

class PipeListener extends AbstractWorkerIPCListener
{
    public function __invoke()
    {
        try {
            $this->workerIPC->checkPipe();
        } catch (Throwable $e) {
            $this->workerPool->setTerminating(true);
        }
    }
}