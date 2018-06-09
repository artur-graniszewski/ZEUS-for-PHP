<?php

namespace ZeusTest;

use \PHPUnit\Framework\TestCase;
use Zend\Console\Console;
use Zend\Http\Request;
use Zend\Http\Response;
use Zeus\Module;
use Zeus\ServerService\Http\Dispatcher\ZendFrameworkDispatcher;
use ZeusTest\Helpers\ZeusFactories;

/**
 * Class ZendFrameworkDispatcherTest
 * @package ZeusTest
 * @runTestsInSeparateProcesses true
 * @preserveGlobalState disabled
 */
class ZendFrameworkDispatcherTest extends \PHPUnit\Framework\TestCase
{
    use ZeusFactories;

    public function setUp()
    {
        parent::setUp();
        mkdir(__DIR__ . '/tmp');
        mkdir(__DIR__ . '/tmp/config');
        chdir(__DIR__ . '/tmp');
        file_put_contents(__DIR__ . '/tmp/config/application.config.php', '<?php return []; ?>');

        ZendFrameworkDispatcher::setApplicationConfig($this->getServiceManager()->get('ApplicationConfig'));
        Module::setOverrideConfig(
            [
                'view_manager' => [
                    'template_map' => [
                        'layout/layout' => __DIR__ . '/Templates/layout.php',
                        '404' => __DIR__ . '/Templates/404.php',
                        'error' => __DIR__ . '/Templates/error.php',
                    ],
                ]
            ]
        );

        ob_start();
    }

    public function tearDown()
    {
        chdir(__DIR__);
        unlink(__DIR__ . '/tmp/config/application.config.php');
        rmdir(__DIR__ . '/tmp/config');
        rmdir(__DIR__ . '/tmp');
        Console::overrideIsConsole(true);

        ob_end_clean();

        parent::tearDown();
    }

    public function testRequestDispatcherInPageNotFoundScenario()
    {
        $dispatcher = new ZendFrameworkDispatcher([], null);

        $httpRequest = Request::fromString("GET / HTTP/1.0\r\n\r\n");
        $httpResponse = new Response();
        /** @var Response $httpResponse */
        $dispatcher->dispatch($httpRequest, $httpResponse);
        $result = ob_get_contents();

        $this->assertGreaterThan(0, strpos($result, 'ERROR 404 DETECTED!'));
        $this->assertGreaterThan(0, strpos($result, 'MESSAGE: Page not found.'));
        $this->assertInstanceOf(Response::class, $httpResponse);
        $this->assertEquals(404, $httpResponse->getStatusCode());
    }
}