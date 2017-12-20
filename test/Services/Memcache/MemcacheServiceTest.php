<?php

namespace ZeusTest\Services\Memcache;

use PHPUnit_Framework_TestCase;
use Zend\Cache\Service\StorageAdapterPluginManagerFactory;
use Zend\Cache\Service\StorageCacheAbstractServiceFactory;
use Zend\Cache\Service\StoragePluginManagerFactory;
use Zend\Cache\Storage\Adapter\Apcu;
use Zend\Cache\Storage\AdapterPluginManager;
use Zend\Cache\Storage\PluginManager;
use Zend\ServiceManager\ServiceManager;
use Zeus\Kernel\Scheduler\SchedulerEvent;
use Zeus\Kernel\Scheduler\WorkerEvent;
use Zeus\ServerService\Memcache\Factory\MemcacheFactory;
use Zeus\ServerService\Memcache\Service;
use ZeusTest\Helpers\ZeusFactories;

class MemcacheServiceTest extends PHPUnit_Framework_TestCase
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
        $sm->setService("config", [
            'caches' => [
            'zeus_server_cache' => [
                'adapter' => [
                    'name'    => 'memory',
                    //'options' => ['ttl' => 3600],
                ],
            ],
            'zeus_client_cache' => [
                'adapter' => [
                    'name'    => 'memory',
                    //'options' => ['ttl' => 3600],
                ],
            ]
        ],]);

        $sm->addAbstractFactory(StorageCacheAbstractServiceFactory::class);
        $sm->setFactory(AdapterPluginManager::class, StorageAdapterPluginManagerFactory::class);
        $sm->setFactory(Service::class, MemcacheFactory::class);
        $sm->setFactory(PluginManager::class, StoragePluginManagerFactory::class);
        $scheduler = $this->getScheduler();
        $events = $scheduler->getEventManager();
        $events->getSharedManager()->attach(
            '*',
            WorkerEvent::EVENT_CREATE, function (WorkerEvent $event) use ($events) {
            $event->setParam("uid", 123456789);
        }, 100
        );
        $logger = $scheduler->getLogger();

        $service = $sm->build(Service::class,
            [
                'service_name' => 'zeus-memcache-test',
                'scheduler_adapter' => $scheduler,
                'logger_adapter' => $logger,
                'config' =>
                [
                    'service_settings' => [
                        'listen_port' => 0,
                        'listen_address' => '0.0.0.0',
                        'server_cache' => 'zeus_server_cache',
                        'client_cache' => 'zeus_client_cache',
                ]
            ]
        ]);

        return $service;
    }

    public function setUp()
    {
        try {
            new Apcu();
        } catch (\Exception $ex) {
            $this->markTestSkipped('Could not use APCu adapter: ' . $ex->getMessage());
        }
    }

    public function testServiceCreation()
    {
        $service = $this->getService();
        $service->start();
        $service->stop();
    }
}