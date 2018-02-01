<?php

namespace ZeusTest\Services;

use \PHPUnit\Framework\TestCase;

use Zend\Log\Logger;
use Zend\Log\Writer\Noop;
use Zeus\ServerService\Manager;
use Zeus\ServerService\ManagerEvent;
use Zeus\ServerService\Shared\Logger\LoggerFactory;
use Zeus\ServerService\Shared\Logger\LoggerInterface;
use ZeusTest\Helpers\DummyServerService;
use ZeusTest\Helpers\ServerServiceManagerPlugin;
use ZeusTest\Helpers\ZeusFactories;

class ServerServiceManagerPluginTest extends \PHPUnit\Framework\TestCase
{
    use ZeusFactories;

    /**
     * @param array $plugin
     * @return Manager
     */
    protected function getManagerWithPlugin(array $plugin)
    {
        $sm = $this->getServiceManager(
            [
                'zeus_process_manager' => [
                    'manager' => [
                        'plugins' => $plugin,
                    ],
                ]
            ]
        );

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
        $tmpDir = __DIR__ . '/../tmp';

        if (!file_exists($tmpDir)) {
            mkdir($tmpDir);
        }
        file_put_contents(__DIR__ . '/../tmp/test.log', '');
    }

    public function tearDown()
    {
        unlink(__DIR__ . '/../tmp/test.log');
        rmdir(__DIR__ . '/../tmp');
        parent::tearDown();
    }

    public function testServiceStart()
    {
        $plugin = new ServerServiceManagerPlugin();
        $manager = $this->getManagerWithPlugin([$plugin]);
        $service = new DummyServerService(['hang' => false], $this->getScheduler(1), $manager->getLogger());
        $manager->registerService('test-service', $service, true);
        $manager->startServices(['test-service']);

        $this->assertContains(ManagerEvent::EVENT_MANAGER_INIT, $plugin->getTriggeredEvents());
        $this->assertContains(ManagerEvent::EVENT_SERVICE_START, $plugin->getTriggeredEvents());
        $this->assertContains(ManagerEvent::EVENT_SERVICE_STOP, $plugin->getTriggeredEvents());
    }
}