<?php

namespace ZeusTest\Unit\Scheduler\Listener;

use PHPUnit\Framework\TestCase;
use Zeus\Kernel\Scheduler\WorkerEvent;
use ZeusTest\Helpers\ZeusFactories;
use Zeus\Kernel\Scheduler\Status\WorkerState;
use Zeus\Kernel\Scheduler\Event\WorkerLoopRepeated;
use Zeus\Kernel\Scheduler\Event\WorkerExited;

class WorkerLifeCycleTest extends TestCase
{
    use ZeusFactories;

    public function testEventsFlow()
    {
        $loopStarted = false;
        $exitDetected = false;
        $scheduler = $this->getScheduler(1);

        $em = $scheduler->getEventManager();
        $em->attach(WorkerLoopRepeated::class, function (WorkerEvent $e) use (&$loopStarted) {
            $e->stopPropagation(true);
            $e->getWorker()->setIsLastTask(true);
            $e->getWorker()->setCode(WorkerState::EXITING);
            $loopStarted = true;
        });

        $em->attach(WorkerExited::class, function (WorkerEvent $e) use (&$exitDetected) {
            $e->stopPropagation(true);

            $exitDetected = true;
        });

        $this->simulateWorkerInit($scheduler->getEventManager());
        $scheduler->start();

        $this->assertTrue($loopStarted, "Loop event should be triggered");
        $this->assertTrue($exitDetected, "Exit event should be triggered");
    }
}