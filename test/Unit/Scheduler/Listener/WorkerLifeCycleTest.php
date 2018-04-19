<?php

namespace ZeusTest\Unit\Scheduler\Listener;

use PHPUnit\Framework\TestCase;
use Zeus\Kernel\Scheduler\WorkerEvent;
use ZeusTest\Helpers\ZeusFactories;

class WorkerLifeCycleTest extends TestCase
{
    use ZeusFactories;

    public function testEventsFlow()
    {
        $loopStarted = false;
        $exitDetected = false;
        $scheduler = $this->getScheduler(1);

        $em = $scheduler->getEventManager();
        $em->attach(WorkerEvent::EVENT_LOOP, function (WorkerEvent $e) use (&$loopStarted) {
            $e->stopPropagation(true);
            $e->getWorker()->setIsLastTask(true);
            $loopStarted = true;
        });

        $em->attach(WorkerEvent::EVENT_EXIT, function (WorkerEvent $e) use (&$exitDetected) {
            $e->stopPropagation(true);

            $exitDetected = true;
        });

        $this->simulateWorkerInit($scheduler->getEventManager());
        $scheduler->start();

        $this->assertTrue($loopStarted, "Loop event should be triggered");
        $this->assertTrue($exitDetected, "Exit event should be triggered");
    }
}