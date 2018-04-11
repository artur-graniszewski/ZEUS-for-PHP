<?php

namespace ZeusTest\Services\Async;

use PHPUnit\Framework\TestCase;
use Zend\Cache\Service\StorageCacheAbstractServiceFactory;
use Zend\ServiceManager\ServiceManager;
use Zeus\Kernel\Scheduler\SchedulerEvent;
use Zeus\Kernel\Scheduler\WorkerEvent;
use Zeus\ServerService\Async\Service;
use Zeus\ServerService\Shared\Factory\AbstractServerServiceFactory;
use ZeusTest\Helpers\ZeusFactories;

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
        $logger = $scheduler->getLogger();
        $events = $scheduler->getEventManager();
        $events->getSharedManager()->attach(
            '*',
            WorkerEvent::EVENT_CREATE, function (WorkerEvent $event) use ($events) {
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
        $service = $this->getService();
        $this->assertFalse($service->getScheduler()->isTerminating());
        $service->getScheduler()->getEventManager()->attach(SchedulerEvent::EVENT_LOOP, function(SchedulerEvent $event) {
            $event->getScheduler()->setTerminating(true);
        });
        $service->start();
        $this->assertTrue($service->getScheduler()->isTerminating());
        $service->stop();
    }
}