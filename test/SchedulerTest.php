<?php

namespace ZeusTest;

use PHPUnit_Framework_TestCase;
use Zend\EventManager\Event;
use Zend\EventManager\EventInterface;
use Zend\Log\Logger;
use Zend\Log\Writer\Noop;
use Zend\Mvc\Application;
use Zend\ServiceManager\ServiceManager;
use Zend\Stdlib\ArrayUtils;
use Zeus\Kernel\IpcServer\Adapter\IpcAdapterInterface;
use Zeus\Kernel\IpcServer\Factory\IpcAdapterAbstractFactory;
use Zeus\Kernel\ProcessManager\Factory\ProcessFactory;
use Zeus\Kernel\ProcessManager\Factory\SchedulerFactory;
use Zeus\Kernel\ProcessManager\Process;
use Zeus\Kernel\ProcessManager\Scheduler;
use Zeus\Kernel\ProcessManager\Scheduler\EventsInterface;
use ZeusTest\Helpers\DummyIpcAdapter;
use ZeusTest\Helpers\DummyServiceFactory;

class SchedulerTest extends PHPUnit_Framework_TestCase
{

    /**
     * @var ServiceManager
     */
    protected $serviceManager;
    /**
     * @var Application
     */
    protected $application;

    public function setUp()
    {
        chdir(__DIR__);
    }

    public function tearDown()
    {
        parent::tearDown();
    }

    /**
     * @return Scheduler
     */
    public function getScheduler()
    {
        $logger = new Logger();
        $logger->addWriter(new Noop());

        $sm = new ServiceManager();
        $sm->addAbstractFactory(IpcAdapterAbstractFactory::class);
        $sm->setFactory(Scheduler::class, SchedulerFactory::class);
        $sm->setFactory(Process::class, ProcessFactory::class);
        $sm->setFactory(IpcAdapterInterface::class, IpcAdapterAbstractFactory::class);
        $sm->setFactory(DummyServiceFactory::class, DummyServiceFactory::class);
        $config = require "../config/module.config.php";

        $config = ArrayUtils::merge($config,
            [
                'zeus_process_manager' => [
                    'schedulers' => [
                        'test_scheduler_1' => [
                            'scheduler_name' => 'test-scheduler',
                            'multiprocessing_module' => DummyServiceFactory::class,
                            'max_processes' => 32,
                            'max_process_tasks' => 100,
                            'min_spare_processes' => 3,
                            'max_spare_processes' => 5,
                            'start_processes' => 8,
                            'enable_process_cache' => true
                        ]
                    ]
                ]
            ]
        );

        $sm->setService('configuration', $config);

        $ipcAdapter = $sm->build(DummyIpcAdapter::class, ['service_name' => 'test-service']);
        $scheduler = $sm->build(Scheduler::class, [
            'ipc_adapter' => $ipcAdapter,
            'service_name' => 'test-service',
            'scheduler_name' => 'test-scheduler',
            'service_logger_adapter' => $logger,
            'main_logger_adapter' => $logger,
        ]);

        return $scheduler;
    }

    public function testApplicationInit()
    {
        $scheduler = $this->getScheduler();
        $this->assertInstanceOf(Scheduler::class, $scheduler);
        $scheduler->setContinueMainLoop(false);
        $scheduler->startScheduler(new Event());
        $this->assertEquals(getmypid(), $scheduler->getId());
    }

    public function testMainLoopIteration()
    {
        $scheduler = $this->getScheduler();
        $this->assertInstanceOf(Scheduler::class, $scheduler);

        $events = $scheduler->getEventManager();
        $counter = 0;
        $events->attach(EventsInterface::ON_SCHEDULER_LOOP, function(EventInterface $e) use (&$counter) {
            $e->getTarget()->setContinueMainLoop(false);
            $counter++;
        });

        $scheduler->startScheduler(new Event());
        $this->assertEquals(1, $counter, 'Loop should have been executed only once');
    }
}