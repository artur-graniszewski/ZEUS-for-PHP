<?php

namespace ZeusTest\Kernel\Scheduler;

use PHPUnit_Framework_TestCase;
use Zend\Console\Console;
use Zend\Log\Logger;
use Zend\Log\Writer\Noop;
use Zeus\Kernel\ProcessManager\Plugin\SchedulerStatus;
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
        $this->markTestSkipped("Scheduler Status feature is being refactored");
        $logger = new Logger();
        $logger->addWriter(new Noop());
        $statuses = [];
        $statusOutputs = [];

        $scheduler = $this->getScheduler(3, function(Scheduler $scheduler) use (&$statuses, &$statusOutputs) {
            $schedulerStatus = $scheduler->getStatus();
            $statuses[] = $schedulerStatus;
            $service = $this->getService($scheduler);
            $schedulerStatusView = new SchedulerStatusView(Console::getInstance());
            $statusOutputs[] = $schedulerStatusView->getStatus($service);
        });

        $em = $scheduler->getEventManager();

        $status = new SchedulerStatus();
        $status->attach($em);

        $em->attach(SchedulerEvent::EVENT_WORKER_CREATE,
            function(SchedulerEvent $e) use (&$amountOfScheduledProcesses, &$processesCreated, $em) {
                $amountOfScheduledProcesses++;

                $uid = 100000000 + $amountOfScheduledProcesses;
                $processesCreated[$uid] = true;
            }
        );

        $scheduler->setLogger($logger);
        $scheduler->start(false);

        $this->assertFalse($statusOutputs[0], "First Scheduler's iteration should not receive status request");
        $this->assertEquals(1, preg_match('~Service Status~', $statusOutputs[2]), 'Output should contain Server Service status');
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