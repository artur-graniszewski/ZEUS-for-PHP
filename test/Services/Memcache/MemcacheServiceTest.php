<?php

namespace ZeusTest\Services\Memcache;

use PHPUnit_Framework_TestCase;
use Zend\Cache\Service\StorageAdapterPluginManagerFactory;
use Zend\Cache\Service\StorageCacheAbstractServiceFactory;
use Zend\Cache\Service\StoragePluginManagerFactory;
use Zend\Cache\Storage\Adapter\Apcu;
use Zend\Cache\Storage\AdapterPluginManager;
use Zend\Cache\Storage\PluginManager;
use Zend\Http\Response;
use Zend\ServiceManager\ServiceManager;
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
            'zeus_internal_cache' => [
                'adapter' => [
                    'name'    => 'apcu',
                    //'options' => ['ttl' => 3600],
                ],
            ],
            'zeus_user_cache' => [
                'adapter' => [
                    'name'    => 'apcu',
                    //'options' => ['ttl' => 3600],
                ],
            ]
        ],]);

        $sm->addAbstractFactory(StorageCacheAbstractServiceFactory::class);
        $sm->setFactory(AdapterPluginManager::class, StorageAdapterPluginManagerFactory::class);
        $sm->setFactory(Service::class, MemcacheFactory::class);
        $sm->setFactory(PluginManager::class, StoragePluginManagerFactory::class);
        $scheduler = $this->getScheduler();
        $logger = $scheduler->getLogger();

        $service = $sm->build(Service::class,
            [
                'service_name' => 'zeus-memcache-test',
                'scheduler_adapter' => $scheduler,
                'logger_adapter' => $logger,
                'config' =>
                [
                    'service_settings' => [
                        'listen_port' => 7071,
                        'listen_address' => '0.0.0.0',
                        'internal_cache' => 'zeus_internal_cache',
                        'user_cache' => 'zeus_user_cache',
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