<?php

namespace ZeusTest;

use PHPUnit_Framework_TestCase;
use Zend\Log\Logger;
use Zend\Log\Writer\Noop;
use Zeus\Kernel\ProcessManager\Scheduler;
use Zeus\Kernel\ProcessManager\SchedulerEvent;
use Zeus\Kernel\ProcessManager\Status\SchedulerStatusView;
use ZeusTest\Helpers\ZeusFactories;

class SchedulerStatusTest extends PHPUnit_Framework_TestCase
{
    use ZeusFactories;

    public function testSchedulerStatus()
    {
        $logger = new Logger();
        $logger->addWriter(new Noop());
        $statuses = [];
        $statusOutputs = [];

        $scheduler = $this->getScheduler(2, function(Scheduler $scheduler) use (&$statuses, &$statusOutputs) {
            $schedulerStatus = $scheduler->getStatus();
            $statuses[] = $schedulerStatus;
            $schedulerStatusView = new SchedulerStatusView($scheduler);
            $statusOutputs[] = $schedulerStatusView->getStatus();
        });

        $em = $scheduler->getEventManager();
        $em->attach(SchedulerEvent::EVENT_PROCESS_CREATE,
            function(SchedulerEvent $e) use (&$amountOfScheduledProcesses, &$processesCreated, $em) {
                $amountOfScheduledProcesses++;

                $uid = 100000000 + $amountOfScheduledProcesses;
                $processesCreated[$uid] = true;
                $e->setName(SchedulerEvent::EVENT_PROCESS_CREATED);
                $e->setParam('uid', $uid);
                $em->triggerEvent($e);
            }
        );

        $scheduler->setLogger($logger);
        $scheduler->start(false);

        $this->assertNull($statuses[0], "First Scheduler's iteration should not receive status request");
        $this->assertArrayHasKey('scheduler_status', $statuses[1]);

        $this->assertFalse($statusOutputs[0], "First Scheduler's iteration should not receive status request");
        $this->assertGreaterThan(1, strlen($statusOutputs[1]), "Output should be present");
        $this->assertEquals(1, preg_match('~Service Status~', $statusOutputs[1]), 'Output should contain Server Service status');
    }

    public function testSchedulerStatusInOfflineSituation()
    {
        $scheduler = $this->getScheduler(1);
        $schedulerStatusView = new SchedulerStatusView($scheduler);
        $statusOutput = $schedulerStatusView->getStatus();
        $this->assertFalse($statusOutput, 'No output should be present when service is offline');
    }
}