<?php

namespace ZeusTest;

use PHPUnit_Framework_TestCase;
use Zend\Console\Console;
use Zend\Http\Request;
use Zend\Http\Response;
use Zend\Log\Logger;
use Zend\Log\Writer\Stream;
use Zeus\Controller\ConsoleController;
use Zeus\Kernel\ProcessManager\MultiProcessingModule\PosixProcess;
use Zeus\ServerService\Shared\Logger\ConsoleLogFormatter;
use Zeus\ServerService\Shared\Logger\ExtraLogProcessor;
use Zeus\ServerService\Shared\Logger\LoggerFactory;
use Zeus\ServerService\Shared\Logger\LoggerInterface;
use ZeusTest\Helpers\DummyServiceFactory;
use ZeusTest\Helpers\ConsoleControllerMock;
use ZeusTest\Helpers\ZeusFactories;

class ZeusControllerTest extends PHPUnit_Framework_TestCase
{
    use ZeusFactories;

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

    /**
     * @param bool $useOriginalClass
     * @return ConsoleController
     */
    public function getController($useOriginalClass = false)
    {
        $sm = $this->getServiceManager();
        $sm->setFactory(LoggerInterface::class, LoggerFactory::class);
        $sm->setFactory(PosixProcess::class, DummyServiceFactory::class);
        $controller = $sm->get($useOriginalClass ? ConsoleController::class : ConsoleControllerMock::class);

        return $controller;
    }

    public function testControllerFactory()
    {
        $controller = $this->getController(true);

        $this->assertInstanceOf(ConsoleController::class, $controller);
    }

    /**
     * @expectedExceptionMessage Zeus\Controller\ConsoleController can only dispatch requests in a console environment
     * @expectedException \InvalidArgumentException
     */
    public function testControllerRequestValidation()
    {
        $controller = $this->getController(true);
        $controller->dispatch(new Request(), new Response());
    }

    public function testControllerServicesList()
    {
        $request = new \Zend\Console\Request([
            __FILE__,
            'zeus',
            'list',
        ]);

        $logger = new Logger();
        $writer = new Stream(__DIR__ . '/tmp/test.log');
        $formatter = new ConsoleLogFormatter(Console::getInstance());
        $writer->setFormatter($formatter);
        $logger->addProcessor(new ExtraLogProcessor());
        $logger->addWriter($writer);
        $response = new \Zend\Console\Response();
        $controller = $this->getController();
        $controller->setLogger($logger);
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

    public function testControllerApplicationAutoStartWithoutServices()
    {
        $request = new \Zend\Console\Request([
            __FILE__,
            'zeus',
            'start',
        ]);

        $response = new \Zend\Console\Response();
        $controller = $this->getController();
        $controller->dispatch($request, $response);

        $logEntries = file_get_contents(__DIR__ . '/tmp/test.log');
        $this->assertGreaterThan(0, strpos($logEntries, 'Started 0 services in '));
        $this->assertGreaterThan(0, strpos($logEntries, 'No server service started'));
    }

    public function testControllerApplicationStopWithoutServices()
    {
        $request = new \Zend\Console\Request([
            __FILE__,
            'zeus',
            'stop',
        ]);

        $response = new \Zend\Console\Response();
        $controller = $this->getController();
        $controller->dispatch($request, $response);

        $logEntries = file_get_contents(__DIR__ . '/tmp/test.log');
        $this->assertGreaterThan(0, strpos($logEntries, 'Stopped 0 service(s)'));
    }
}