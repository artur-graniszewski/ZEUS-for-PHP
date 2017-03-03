<?php

namespace ZeusTest;

use PHPUnit_Framework_TestCase;
use Zend\Http\Request;
use Zend\Http\Response;
use Zeus\Controller\ZeusController;
use Zeus\Kernel\ProcessManager\MultiProcessingModule\PosixProcess;
use Zeus\ServerService\Shared\Logger\LoggerFactory;
use Zeus\ServerService\Shared\Logger\LoggerInterface;
use ZeusTest\Helpers\DummyServiceFactory;
use ZeusTest\Helpers\ZeusFactories;

class ZeusControllerTest extends PHPUnit_Framework_TestCase
{
    use ZeusFactories;

    public function setUp()
    {
        parent::setUp();
        file_put_contents(__DIR__ . '/tmp/test.log', '');
    }

    public function tearDown()
    {
    }

    /**
     * @return ZeusController
     */
    public function getController()
    {
        $sm = $this->getServiceManager();
        $sm->setFactory(LoggerInterface::class, LoggerFactory::class);
        $sm->setFactory(PosixProcess::class, DummyServiceFactory::class);
        $controller = $sm->get(ZeusController::class);

        return $controller;
    }

    public function testControllerFactory()
    {
        $controller = $this->getController();

        $this->assertInstanceOf(ZeusController::class, $controller);
    }

    /**
     * @expectedExceptionMessage Zeus\Controller\ZeusController can only dispatch requests in a console environment
     * @expectedException \InvalidArgumentException
     */
    public function testControllerRequestValidation()
    {
        $controller = $this->getController();
        $controller->dispatch(new Request(), new Response());
    }

    public function testControllerServicesList()
    {
        $request = new \Zend\Console\Request([
            __FILE__,
            'zeus',
            'list',
        ]);

        $response = new \Zend\Console\Response();
        $controller = $this->getController();
        $controller->dispatch($request, $response);

        $logEntries = file_get_contents(__DIR__ . '/tmp/test.log');
        $sentences = [
            'Service configuration for "zeus_httpd"',
            '[listen_port] => 7070',
            '[listen_address] => 0.0.0.0'
        ];
        foreach ($sentences as $sentence) {
            $this->assertGreaterThan(0, strpos($logEntries, $sentence));
        }
    }

    public function testControllerServicesListForIncorrectService()
    {
        $request = new \Zend\Console\Request([
            __FILE__,
            'zeus',
            'list',
            'dummy_service'
        ]);

        $response = new \Zend\Console\Response();
        $controller = $this->getController();
        $controller->dispatch($request, $response);

        $logEntries = file_get_contents(__DIR__ . '/tmp/test.log');
        $this->assertGreaterThan(0, strpos($logEntries, 'Exception (0): Service "dummy_service" not found'));
    }

    public function testControllerServicesStatus()
    {
        $request = new \Zend\Console\Request([
            __FILE__,
            'zeus',
            'status',
        ]);

        $response = new \Zend\Console\Response();
        $controller = $this->getController();
        $controller->dispatch($request, $response);

        $logEntries = file_get_contents(__DIR__ . '/tmp/test.log');
        $this->assertGreaterThan(0, strpos($logEntries, 'Service "zeus_httpd" is offline or too busy to respond'));
    }
}