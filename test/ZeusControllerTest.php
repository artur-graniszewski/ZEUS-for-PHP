<?php

namespace ZeusTest;

use PHPUnit\Framework\TestCase;
use Zend\Console\Console;
use Zend\Console\Request as ConsoleRequest;
use Zend\Console\Response as ConsoleResponse;
use Zend\Http\Request;
use Zend\Http\Response;
use Zend\Log\Logger;
use Zend\Log\Writer\Stream;
use Zeus\Controller\MainController;
use Zeus\Kernel\System\Runtime;
use Zeus\ServerService\Shared\Logger\ConsoleLogFormatter;
use Zeus\ServerService\Shared\Logger\ExtraLogProcessor;
use Zeus\ServerService\Shared\Logger\LoggerFactory;
use Zeus\ServerService\Shared\Logger\LoggerInterface;
use ZeusTest\Helpers\MainControllerMock;
use ZeusTest\Helpers\ZeusFactories;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class ZeusControllerTest extends TestCase
{
    use ZeusFactories;

    public function setUp()
    {
        parent::setUp();
        Runtime::setShutdownHook(function() {
            return true;
        });
        $tmpDir = __DIR__ . '/tmp';

        if (!file_exists($tmpDir)) {
            mkdir($tmpDir);
        }
        file_put_contents(__DIR__ . '/tmp/test.log', '');
        Runtime::setShutdownHook(function() {
            return true;
        });
    }

    public function tearDown()
    {
        Runtime::setShutdownHook(function() {
            return false;
        });
        @unlink(__DIR__ . '/tmp/test.log');
        @rmdir(__DIR__ . '/tmp');
        parent::tearDown();
    }

    /**
     * @param bool $useOriginalClass
     * @return MainController
     */
    public function getController($useOriginalClass = false)
    {
        $sm = $this->getServiceManager();
        $sm->setFactory(LoggerInterface::class, LoggerFactory::class);
        $controller = $sm->get($useOriginalClass ? MainController::class : MainControllerMock::class);

        return $controller;
    }

    public function testControllerFactory()
    {
        $controller = $this->getController(true);

        $this->assertInstanceOf(MainController::class, $controller);
    }

    /**
     * @expectedExceptionMessage Zeus\Controller\MainController can only dispatch requests in a console environment
     * @expectedException \InvalidArgumentException
     */
    public function testControllerRequestValidation()
    {
        $controller = $this->getController(true);
        $controller->dispatch(new Request(), new Response());
    }

    public function testControllerServicesList()
    {
        $request = new ConsoleRequest([
            __FILE__,
            'zeus',
            'list',
            'zeus_httpd',
        ]);

        $logger = new Logger();
        $writer = new Stream(__DIR__ . '/tmp/test.log');
        $formatter = new ConsoleLogFormatter(Console::getInstance());
        $writer->setFormatter($formatter);
        $logger->addProcessor(new ExtraLogProcessor());
        $logger->addWriter($writer);
        $response = new ConsoleResponse();
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
            $this->assertGreaterThan(0, strpos($logEntries, $sentence), "Missing sentence " . $sentence . "\nGot:\n" . $logEntries);
        }
    }

    public function testControllerServicesStopOnNonExistingService()
    {
        $request = new ConsoleRequest([
            __FILE__,
            'zeus',
            'stop',
            'dummyservice123456',
        ]);

        $logger = new Logger();
        $writer = new Stream(__DIR__ . '/tmp/test.log');
        $formatter = new ConsoleLogFormatter(Console::getInstance());
        $writer->setFormatter($formatter);
        $logger->addProcessor(new ExtraLogProcessor());
        $logger->addWriter($writer);
        $response = new ConsoleResponse();
        $controller = $this->getController();
        $controller->setLogger($logger);
        $controller->dispatch($request, $response);

        $logEntries = file_get_contents(__DIR__ . '/tmp/test.log');
        $sentences = [
            'Only 0 out of 1 services were stopped gracefully',
            'Stopped 0 service(s)',
        ];

        foreach ($sentences as $sentence) {
            $this->assertGreaterThan(0, strpos($logEntries, $sentence), "Missing sentence " . $sentence . "\nGot:\n" . $logEntries);
        }
    }

    public function testControllerServicesListForIncorrectService()
    {
        $request = new ConsoleRequest([
            __FILE__,
            'zeus',
            'list',
            'dummy_service'
        ]);

        $response = new ConsoleResponse();
        $controller = $this->getController();
        $controller->dispatch($request, $response);

        $logEntries = file_get_contents(__DIR__ . '/tmp/test.log');
        $this->assertGreaterThan(0, strpos($logEntries, 'Exception (0): Service "dummy_service" not found'));
    }

    public function testControllerServicesStatusWhenOffline()
    {
        $request = new ConsoleRequest([
            __FILE__,
            'zeus',
            'status',
        ]);

        $response = new ConsoleResponse();
        $controller = $this->getController();
        $controller->dispatch($request, $response);

        $logEntries = file_get_contents(__DIR__ . '/tmp/test.log');
        $this->assertGreaterThan(0, strpos($logEntries, 'Service "zeus_httpd" is offline or too busy to respond'));
    }

    public function testControllerApplicationAutoStartWithoutServices()
    {
        $request = new ConsoleRequest([
            __FILE__,
            'zeus',
            'start',
        ]);

        $response = new ConsoleResponse();
        $controller = $this->getController();
        $controller->dispatch($request, $response);

        $logEntries = file_get_contents(__DIR__ . '/tmp/test.log');
        $this->assertGreaterThan(0, strpos($logEntries, 'No server service started'), "Log contains $logEntries");
    }

    public function testControllerApplicationStopWithoutServices()
    {
        $request = new ConsoleRequest([
            __FILE__,
            'zeus',
            'stop',
        ]);

        $response = new ConsoleResponse();
        $controller = $this->getController();
        $controller->dispatch($request, $response);

        $logEntries = file_get_contents(__DIR__ . '/tmp/test.log');
        $this->assertGreaterThan(0, strpos($logEntries, 'Stopped 0 service(s)'));
    }
}