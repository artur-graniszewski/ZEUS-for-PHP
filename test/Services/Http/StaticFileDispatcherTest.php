<?php

namespace ZeusTest\Services\Http;

use PHPUnit_Framework_TestCase;
use Zend\Http\Request;
use Zend\Http\Response;
use Zeus\ServerService\Http\Dispatcher\StaticFileDispatcher;
use ZeusTest\Helpers\DummyFileDispatcher;

class StaticFileDispatcherTest extends PHPUnit_Framework_TestCase
{
    protected function getTmpDir()
    {
        $tmpDir = __DIR__ . '/tmp/';

        if (!file_exists($tmpDir)) {
            mkdir($tmpDir);
        }
        return __DIR__ . '/tmp/';
    }

    public function setUp()
    {
        parent::setUp();

        $this->getTmpDir();
        chdir(__DIR__);

        $xml = '<?xml data="test"></xml>';
        file_put_contents('tmp/test.xml', $xml);
        file_put_contents('tmp/test_xml', $xml);

        $json = '{"text": "test"}';
        file_put_contents('tmp/test.json', $json);

        ob_start();
    }

    public function tearDown()
    {
        parent::tearDown();
        ob_end_clean();

        unlink("tmp/test.xml");
        unlink("tmp/test_xml");
        unlink("tmp/test.json");
        rmdir("tmp");
    }

    public function testMimeTypeDetector()
    {
        $config['public_directory'] = 'tmp/';
        $dispatcher = new StaticFileDispatcher($config);

        $request = Request::fromString("GET test.xml HTTP/1.0\r\n\r\n");
        $response = new Response();
        $dispatcher->dispatch($request, $response);

        $this->assertEquals(Response::STATUS_CODE_200, $response->getStatusCode());
        $this->assertEquals('application/xml', $response->getHeaders()->get('Content-Type')->getFieldValue());

        $request = Request::fromString("GET test_xml HTTP/1.0\r\n\r\n");
        $response = new Response();
        $dispatcher->dispatch($request, $response);

        $this->assertEquals(Response::STATUS_CODE_200, $response->getStatusCode());

        $this->assertEquals('application/xml', $response->getHeaders()->get('Content-Type')->getFieldValue());
    }

    public function testFileExtensionBlacklist()
    {
        $config['public_directory'] = 'tmp/';
        $config['blocked_file_types'] = ['xml'];
        $dispatcher = new StaticFileDispatcher($config);

        $request = Request::fromString("GET test.xml HTTP/1.0\r\n\r\n");
        $response = new Response();
        $dispatcher->dispatch($request, $response);
        $this->assertEquals(Response::STATUS_CODE_403, $response->getStatusCode());

        $config['blocked_file_types'] = ['xml', 'json'];

        $dispatcher = new StaticFileDispatcher($config);

        $request = Request::fromString("GET test.json HTTP/1.0\r\n\r\n");
        $response = new Response();
        $dispatcher->dispatch($request, $response);
        $this->assertEquals(Response::STATUS_CODE_403, $response->getStatusCode());
    }

    public function testIfDirectoryListingIsForbidden()
    {
        $config['public_directory'] = 'tmp/';

        $dispatcher = new StaticFileDispatcher($config);

        $request = Request::fromString("GET / HTTP/1.0\r\n\r\n");
        $response = new Response();
        $dispatcher->dispatch($request, $response);
        $this->assertEquals(Response::STATUS_CODE_403, $response->getStatusCode());
    }

    public function testAgainstPathTraversalAttack()
    {
        $config['public_directory'] = 'tmp/';

        $dispatcher = new StaticFileDispatcher($config);

        $fileName = basename(__FILE__);

        $request = Request::fromString("GET ../$fileName HTTP/1.0\r\n\r\n");
        $response = new Response();
        $dispatcher->dispatch($request, $response);
        $this->assertEquals(Response::STATUS_CODE_400, $response->getStatusCode());
    }

    public function testAnotherDispatcherWasUsed()
    {
        $config['public_directory'] = 'tmp/';

        $anotherDispatcher = new DummyFileDispatcher($config);
        $dispatcher = new StaticFileDispatcher($config, $anotherDispatcher);
        $request = Request::fromString("GET incorrect.path HTTP/1.0\r\n\r\n");
        $response = new Response();
        $dispatcher->dispatch($request, $response);

        $this->assertTrue($anotherDispatcher->isDispatchPerformed());
    }
}