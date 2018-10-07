<?php

namespace Zeus\Kernel\Scheduler\Listener;

use Zeus\Kernel\Scheduler\Shared\WorkerCollection;
use Zeus\Kernel\Scheduler\WorkerEvent;
use Zend\Log\LoggerInterface;

class WorkerExitListener
{
    /** @var LoggerInterface **/
    private $logger;
    
    /** @var WorkerCollection **/
    private $workers;
    
    public function __construct(LoggerInterface $logger, WorkerCollection $workers)
    {
        $this->logger = $logger;
        $this->workers = $workers;
    }
    
    public function __invoke(WorkerEvent $event)
    {
        $uid = $event->getWorker()->getUid();

        $this->logger->debug("Worker $uid exited");

        if (isset($this->workers[$uid])) {
            $workerState = $this->workers[$uid];

            if (!$workerState->isExiting()/* && $workerState->getTime() < microtime(true) - $scheduler->getConfig()->getProcessIdleTimeout()*/) {
                $this->logger->err("Worker $uid exited prematurely");
            }

            unset($this->workers[$uid]);
        }
    }
}