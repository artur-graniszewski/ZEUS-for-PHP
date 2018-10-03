<?php

namespace Zeus\Kernel\Scheduler\Listener;

use Zend\Log\Logger;
use Zeus\Kernel\Scheduler\WorkerEvent;
use Zend\Log\LoggerInterface;
use Zeus\Kernel\SchedulerInterface;

class WorkerExitListener
{
    /** @var LoggerInterface **/
    private $logger;
    
    /** @var SchedulerInterface **/
    private $scheduler;
    
    public function __construct(LoggerInterface $logger, SchedulerInterface $scheduler)
    {
        $this->logger = $logger;
        $this->scheduler = $scheduler;
    }
    
    public function __invoke(WorkerEvent $event)
    {
        $uid = $event->getWorker()->getUid();

        $this->logger->debug("Worker $uid exited");
        $workers = $this->scheduler->getWorkers();

        if (isset($workers[$uid])) {
            $workerState = $workers[$uid];

            if (!$workerState->isExiting()/* && $workerState->getTime() < microtime(true) - $scheduler->getConfig()->getProcessIdleTimeout()*/) {
                $this->logger->err("Worker $uid exited prematurely");
            }

            unset($workers[$uid]);
        }
    }
}