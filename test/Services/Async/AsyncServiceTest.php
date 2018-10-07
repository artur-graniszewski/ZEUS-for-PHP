<?php

namespace ZeusTest\Services\Async;

use PHPUnit\Framework\TestCase;
use Zend\Cache\Service\StorageCacheAbstractServiceFactory;
use Zend\Log\Logger;
use Zend\Log\Writer\Noop;
use Zend\ServiceManager\ServiceManager;
use Zeus\Kernel\Scheduler\SchedulerEvent;
use Zeus\Kernel\Scheduler\WorkerEvent;
use Zeus\ServerService\Async\Service;
use Zeus\ServerService\Shared\Factory\AbstractServerServiceFactory;
use ZeusTest\Helpers\DummyMpm;
use ZeusTest\Helpers\ZeusFactories;
use Zeus\Kernel\Scheduler\Command\CreateWorker;

/**
 * Class AsyncServiceTest
 * @package ZeusTest\Services\Async
 * @runTestsInSeparateProcesses true
 * @preserveGlobalState disabled
 */
class AsyncServiceTest extends TestCase
{
    use ZeusFactories;

    /**
     * @return Service
     */
    protected function getService()
    {
        /** @var ServiceManager $sm */
        $sm = $this->getServiceManager();
        $sm->setAllowOverride(true);

        $sm->addAbstractFactory(StorageCacheAbstractServiceFactory::class);
        $sm->setFactory(Service::class, AbstractServerServiceFactory::class);
        $scheduler = $this->getScheduler();
        $logger = new Logger();
        $logger->addWriter(new Noop());
        $events = $scheduler->getEventManager();
        $events->getSharedManager()->attach(
            '*',
            CreateWorker::class, function (WorkerEvent $event) use ($events) {
            $event->setParam("uid", 123456789);
        }, 100
        );

        $service = $sm->build(Service::class, [
                'service_name' => 'zeus-async-test',
                'scheduler_adapter' => $scheduler,
                'logger_adapter' => $logger,
                'config' => [
                    'service_settings' => [
                        'listen_port' => 0,
                        'listen_address' => '127.0.0.1',
                    ]
                ]
            ]
        );

        return $service;
    }

    /**
     * @expectedException \Zeus\Kernel\Scheduler\Exception\SchedulerException
     * @expectedExceptionMessage Scheduler not running
     */
    public function testServiceCreation()
    {
        DummyMpm::getCapabilities()->setSharedInitialAddressSpace(true);
        $service = $this->getService();
        $this->assertFalse($service->getScheduler()->isTerminating());
        $service->getScheduler()->getEventManager()->attach(SchedulerEvent::INTERNAL_EVENT_KERNEL_START, function(SchedulerEvent $event) {
            $event->getScheduler()->setTerminating(true);
        });
        $service->start();
        $this->assertTrue($service->getScheduler()->isTerminating());
        $service->stop();
    }
}