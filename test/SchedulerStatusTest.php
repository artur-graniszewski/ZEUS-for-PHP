<?php

namespace ZeusTest;

use PHPUnit_Framework_TestCase;
use Zend\Console\Console;
use Zend\Log\Logger;
use Zend\Log\Writer\Noop;
use Zeus\Kernel\ProcessManager\Scheduler;
use Zeus\Kernel\ProcessManager\SchedulerEvent;
use Zeus\Kernel\ProcessManager\Status\SchedulerStatusView;
use ZeusTest\Helpers\ZeusFactories;
use Zeus\ServerService\Http\Service;

class SchedulerStatusTest extends PHPUnit_Framework_TestCase
{
    use ZeusFactories;

    /**
     * @param $scheduler
     * @return Service
     */
    protected function getService($scheduler)
    {
        $sm = $this->getServiceManager();
        $logger = $scheduler->getLogger();

        $service = $sm->build(Service::class,
            [
                'scheduler_adapter' => $scheduler,
                'logger_adapter' => $logger,
                'config' =>
                    [
                        'service_settings' => [
                            'listen_port' => 7070,
                            'listen_address' => '0.0.0.0',
                            'keep_alive_enabled' => true,
                            'keep_alive_timeout' => 5,
                            'max_keep_alive_requests_limit' => 100,
                            'blocked_file_types' => [
                                'php',
                                'phtml'
                            ]
                        ]
                    ]
            ]);

        return $service;
    }

    public function testSchedulerStatus()
    {
        $logger = new Logger();
        $logger->addWriter(new Noop());
        $statuses = [];
        $statusOutputs = [];

        $scheduler = $this->getScheduler(2, function(Scheduler $scheduler) use (&$statuses, &$statusOutputs) {
            $schedulerStatus = $scheduler->getStatus();
            $statuses[] = $schedulerStatus;
            $service = $this->getService($scheduler);
            $schedulerStatusView = new SchedulerStatusView(Console::getInstance());
            $statusOutputs[] = $schedulerStatusView->getStatus($service);
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
        $schedulerStatusView = new SchedulerStatusView(Console::getInstance());
        $service = $this->getService($scheduler);
        $statusOutput = $schedulerStatusView->getStatus($service);
        $this->assertFalse($statusOutput, 'No output should be present when service is offline');
    }
}