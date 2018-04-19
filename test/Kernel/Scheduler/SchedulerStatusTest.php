<?php

namespace ZeusTest\Kernel\Scheduler;

use PHPUnit\Framework\TestCase;
use Zend\Console\Console;
use Zend\Log\Logger;
use Zend\Log\Writer\Noop;
use Zeus\Kernel\SchedulerInterface;
use Zeus\ServerService\Plugin\SchedulerStatus;
use Zeus\Kernel\Scheduler;
use Zeus\Kernel\Scheduler\Status\SchedulerStatusView;
use Zeus\Kernel\Scheduler\WorkerEvent;
use ZeusTest\Helpers\DummyServerService;
use ZeusTest\Helpers\ZeusFactories;
use Zeus\ServerService\Http\Service;
use ZeusTest\Unit\IO\DummySelectableStream;

class SchedulerStatusTest extends TestCase
{
    use ZeusFactories;

    /**
     * @param $scheduler
     * @return Service
     */
    protected function getService(SchedulerInterface $scheduler)
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

    /**
     * @param mixed $plugin
     * @return \Zeus\Kernel\SchedulerInterface
     */
    protected function getSchedulerWithPlugin($plugin)
    {
        $sm = $this->getServiceManager(
            [
                'zeus_process_manager' => [
                    'schedulers' => [
                        'test_scheduler_1' => [
                            'plugins' => $plugin
                        ]
                    ]
                ]
            ]
        );

        return $this->getScheduler(2, null, $sm);
    }

    /**
     * @return \PHPUnit_Framework_MockObject_MockObject|SchedulerStatus
     */
    protected function getPluginMock()
    {
        $pluginBuilder = $this->getMockBuilder(SchedulerStatus::class);
        $pluginBuilder->setMethods([
            'getSchedulerStream',
            'getClientStream',
        ]);

        $pluginBuilder->enableOriginalConstructor();
        $pluginBuilder->setConstructorArgs([[
            'ipc_type' => 'socket',
            'listen_address' => '127.0.0.4',
            'listen_port' => 8000
        ]]
        );
        $plugin = $pluginBuilder->getMock();

        return $plugin;
    }

    /**
     * @return \PHPUnit_Framework_MockObject_MockObject|Scheduler\Reactor
     */
    protected function getReactorMock()
    {
        $mockBuilder = $this->getMockBuilder(Scheduler\Reactor::class);
        $mockBuilder->setMethods([
            'observe',
        ]);

        //$mockBuilder->disableOriginalConstructor();
        $mock = $mockBuilder->getMock();

        return $mock;
    }

    public function testSchedulerStatus()
    {
        $dummyStream = new DummySelectableStream(null);
        $mockedPlugin = $this->getPluginMock();
        $mockedPlugin->expects($this->atLeastOnce())->method("getClientStream")->will($this->returnValue($dummyStream));

        //$this->markTestSkipped("Scheduler Status feature is being refactored");
        $logger = new Logger();
        $logger->addWriter(new Noop());

        $scheduler = $this->getSchedulerWithPlugin([$mockedPlugin]);

        $em = $scheduler->getEventManager();
        $em->attach(WorkerEvent::EVENT_INIT, function(WorkerEvent $event) {
            $event->stopPropagation(true); // block process main loop
        }, WorkerEvent::PRIORITY_FINALIZE + 1);

        $em->attach(WorkerEvent::EVENT_CREATE,
            function(WorkerEvent $e) use (&$amountOfScheduledProcesses, &$processesCreated, $em) {
                $amountOfScheduledProcesses++;

                $uid = 100000000 + $amountOfScheduledProcesses;
                $processesCreated[$uid] = true;
            }
        );

        $this->simulateWorkerInit($em);

        $mockedReactor = $this->getReactorMock();
        $mockedReactor->expects($this->atLeastOnce())->method("observe")->will($this->returnCallback(
            function($selector, $onSelectCallback) use ($mockedReactor) {
                $onSelectCallback($selector);
            }
        ));

        $scheduler->setReactor($mockedReactor);
        $scheduler->setLogger($logger);
        $scheduler->start(false);

        $dummyStream->setDataToRead($dummyStream->getWrittenData());

        $mockedPlugin->expects($this->atLeastOnce())->method("getSchedulerStream")->will($this->returnValue($dummyStream));

        $console = Console::getInstance();
        $view = new SchedulerStatusView($console);
        $dummyService = new DummyServerService(['hang' => false], $scheduler, $scheduler->getLogger());
        $dummyService->setScheduler($scheduler);
        $output = $view->getStatus($dummyService);

        $this->assertEquals(1, preg_match('~Service Status~', $output), 'Output should contain Server Service status');
    }

    public function testSchedulerStatusInOfflineSituation()
    {
        $scheduler = $this->getSchedulerWithPlugin([
            SchedulerStatus::class => [
                'ipc_type' => 'socket',
                'listen_address' => '127.0.0.5',
                'listen_port' => 12345
            ]
        ]);
        $schedulerStatusView = new SchedulerStatusView(Console::getInstance());
        $service = $this->getService($scheduler);
        $statusOutput = $schedulerStatusView->getStatus($service);
        $this->assertEquals('', $statusOutput, 'No output should be present when service is offline');
    }

    /**
     * @expectedExceptionMessage Service with name "Zeus\Kernel\SchedulerInterface" could not be created. Reason: Invalid IPC type selected
     * @expectedException \Zend\ServiceManager\Exception\ServiceNotCreatedException
     */
    public function testIpcTypeValidation()
    {
        $this->getSchedulerWithPlugin([
            SchedulerStatus::class => [
                'ipc_type' => 'dummy',
                'listen_address' => '127.0.0.5',
                'listen_port' => 12345
            ]
        ]);
    }

    /**
     * @expectedExceptionMessage Service with name "Zeus\Kernel\SchedulerInterface" could not be created. Reason: Listen port or address is missing
     * @expectedException \Zend\ServiceManager\Exception\ServiceNotCreatedException
     */
    public function testListenAddressValidation()
    {
        $this->getSchedulerWithPlugin([
            SchedulerStatus::class => [
                'ipc_type' => 'socket',
                'listen_port' => 12345
            ]
        ]);
    }

    /**
     * @expectedExceptionMessage Service with name "Zeus\Kernel\SchedulerInterface" could not be created. Reason: Listen port or address is missing
     * @expectedException \Zend\ServiceManager\Exception\ServiceNotCreatedException
     */
    public function testListenPortValidation()
    {
        $this->getSchedulerWithPlugin([
            SchedulerStatus::class => [
                'ipc_type' => 'socket',
                'listen_address' => '127.0.0.5',
            ]
        ]);
    }
}