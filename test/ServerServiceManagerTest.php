<?php

namespace ZeusTest;

use PHPUnit_Framework_TestCase;
use Zend\EventManager\EventManager;
use Zend\Log\Logger;
use Zend\Log\Writer\Noop;
use Zeus\ServerService\Manager;
use Zeus\ServerService\ManagerEvent;
use Zeus\ServerService\Shared\Logger\LoggerFactory;
use Zeus\ServerService\Shared\Logger\LoggerInterface;
use ZeusTest\Helpers\DummyServerService;
use ZeusTest\Helpers\ZeusFactories;

class ServerServiceManagerTest extends PHPUnit_Framework_TestCase
{
    use ZeusFactories;

    protected function getManager()
    {
        $sm = $this->getServiceManager();
        $logger = new Logger();
        $logger->addWriter(new Noop());

        $sm->setFactory(LoggerInterface::class, LoggerFactory::class);

        /** @var Manager $manager */
        $manager = $sm->get(Manager::class);

        return $manager;
    }

    public function setUp()
    {
        parent::setUp();
        $tmpDir = __DIR__ . '/tmp';

        if (!file_exists($tmpDir)) {
            mkdir($tmpDir);
        }
        file_put_contents(__DIR__ . '/tmp/test.log', '');
    }

    public function tearDown()
    {
        unlink(__DIR__ . '/tmp/test.log');
        rmdir(__DIR__ . '/tmp');
        parent::tearDown();
    }

    public function testServicesStart()
    {
        $manager = $this->getManager();
        $service = new DummyServerService([], $this->getScheduler(1), $manager->getLogger());
        $manager->registerService('test-service', $service, true);
        $manager->startServices(['test-service']);

        $logEntries = file_get_contents(__DIR__ . '/tmp/test.log');
        $this->assertGreaterThan(0, strpos($logEntries, 'Started 1 services in '));
    }

    public function testServiceStart()
    {
        $manager = $this->getManager();
        $service = new DummyServerService([], $this->getScheduler(1), $manager->getLogger());
        $manager->registerService('test-service', $service, true);
        $manager->startService('test-service');

        $logEntries = file_get_contents(__DIR__ . '/tmp/test.log');
        $this->assertGreaterThan(0, strpos($logEntries, 'Started 1 services in '));
    }

    public function testManagerEvents()
    {
        $eventsFlow = [];
        $eventHandler = function(ManagerEvent $e) use (& $eventsFlow) {
            $eventsFlow[] = $e->getName();
            $this->assertInstanceOf(Manager::class, $e->getManager());
        };

        $manager = $this->getManager();
        $manager->getEventManager()->attach('*', $eventHandler);

        $service = new DummyServerService([], $this->getScheduler(1), $manager->getLogger());
        $manager->registerService('test-service', $service, true);
        $manager->startServices(['test-service']);

        $this->assertContains(ManagerEvent::EVENT_SERVICE_START, $eventsFlow);
        $this->assertContains(ManagerEvent::EVENT_SERVICE_STOP, $eventsFlow);
        $this->assertContains(ManagerEvent::EVENT_MANAGER_INIT, $eventsFlow);
    }

    public function testManagerSignalHandling()
    {
        $eventsFlow = [];
        $eventHandler = function(ManagerEvent $e) use (& $eventsFlow) {
            $eventsFlow[] = $e->getName();
            $this->assertInstanceOf(Manager::class, $e->getManager());
        };

        $manager = $this->getManager();
        $manager->getEventManager()->attach('*', $eventHandler);

        $service = new DummyServerService(['hang' => true], $this->getScheduler(1), $manager->getLogger());
        $manager->registerService('test-service', $service, true);
        $manager->startServices(['test-service']);

        $this->assertContains(ManagerEvent::EVENT_SERVICE_START, $eventsFlow);
        $this->assertContains(ManagerEvent::EVENT_SERVICE_STOP, $eventsFlow);
    }

    public function testThatDestructorDetachesEvents()
    {
        $mockBuilder = $this->getMockBuilder(EventManager::class);
        $mockBuilder->setMethods([
            'detach',
        ]);

        $events = $mockBuilder->getMock();
        $events->expects($this->atLeastOnce())
            ->method('detach');

        $manager = new Manager([]);
        $manager->setEventManager($events);
        $logger = new Logger();
        $logger->addWriter(new Noop());
        $manager->setLogger($logger);
        $service = new DummyServerService([], $this->getScheduler(1), $logger);
        $manager->registerService('test-service', $service, true);
        $manager->startServices(['test-service']);
        $manager->__destruct();
    }
}