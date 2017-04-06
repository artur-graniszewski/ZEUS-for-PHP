<?php

namespace ZeusTest\Services\Async;

use PHPUnit_Framework_TestCase;
use Zend\ServiceManager\ServiceManager;
use Zeus\ServerService\Async\AsyncPlugin;
use Zeus\ServerService\Async\Config;
use Zeus\ServerService\Async\Factory\AsyncPluginFactory;

class AsyncPluginTest extends PHPUnit_Framework_TestCase
{
    const TEST_PORT = 9999;

    protected $sockets;

    public function setUp()
    {
        $domain = (strtoupper(substr(PHP_OS, 0, 3)) == 'WIN' ? AF_INET : AF_UNIX);
        socket_create_pair($domain, SOCK_STREAM, 0, $this->sockets);
        socket_set_nonblock($this->sockets[1]);
        //$this->client = socket_accept($sock);
    }

    public function tearDown()
    {
        if (is_resource($this->sockets[0])) {
            socket_close($this->sockets[0]);
        }
        if (is_resource($this->sockets[1])) {
            socket_close($this->sockets[1]);
        }
    }

    /**
     * @param bool $asMock
     * @return AsyncPlugin
     */
    protected function getPlugin($asMock)
    {
        $factory = new AsyncPluginFactory();
        $container = new ServiceManager();
        $container->setService('configuration', [
            'zeus_process_manager' => [
                'services' => [
                    'zeus_async' => [
                        'auto_start' => false,
                        'service_name' => 'zeus_async',
                        'scheduler_name' => 'zeus_web_scheduler',
                        'service_adapter' => \Zeus\ServerService\Async\Service::class,
                        'service_settings' => [
                            'listen_port' => static::TEST_PORT,
                            'listen_address' => '127.0.0.1',
                        ],
                    ]
                ]
            ]
        ]);

        if (!$asMock) {
            return $factory($container, AsyncPlugin::class, []);
        }

        $config = $container->get('configuration');
        $config = new Config($config['zeus_process_manager']['services']['zeus_async']['service_settings']);

        $mockBuilder = $this->getMockBuilder(AsyncPlugin::class);
        $mockBuilder->setConstructorArgs([$config]);
        $mockBuilder->setMethods(['getSocket']);

        return $mockBuilder->getMock();
    }

    public function testPluginInstantiation()
    {
        $this->getPlugin(false);
    }

    /**
     * @expectedException \RuntimeException
     * @expectedExceptionMessage Async call failed, server response: BAD_REQUEST
     */
    public function testErrorHandlingOnRun()
    {
        socket_write($this->sockets[1], "BAD_REQUEST\n");
        $plugin = $this->getPlugin(true);
        $plugin
            ->expects($this->any())
            ->method('getSocket')
            ->willReturn($this->sockets[0]);

        $plugin->run(function() { return "ok"; });
    }

    public function testSocketIsClosedOnError()
    {
        socket_write($this->sockets[1], "BAD_REQUEST\n");
        $plugin = $this->getPlugin(true);
        $plugin
            ->expects($this->any())
            ->method('getSocket')
            ->willReturn($this->sockets[0]);

        try {
            $plugin->run(function () {
                return "ok";
            });
        } catch (\Exception $e) {
            $this->assertFalse(is_resource($this->sockets[0]), "Socket should be closed after error");
            return;
        }

        $this->fail('No exception detected on error');
    }

    public function testProcessingOnRun()
    {
        socket_write($this->sockets[1], "PROCESSING\n");
        $plugin = $this->getPlugin(true);
        $plugin
            ->expects($this->any())
            ->method('getSocket')
            ->willReturn($this->sockets[0]);

        $plugin->run(function() { return "ok"; });
    }

    public function testIsWorking()
    {
        socket_write($this->sockets[1], "PROCESSING\n");
        $plugin = $this->getPlugin(true);
        $plugin
            ->expects($this->any())
            ->method('getSocket')
            ->willReturn($this->sockets[0]);

        $id = $plugin->run(function() { return "ok"; });
        $isWorking = $plugin->isWorking($id);
        $this->assertTrue($isWorking, 'Callback should be reported as working');
    }

    public function testResultOnJoin()
    {
        $data = "OK! " . microtime(true);
        $message = serialize($data);
        $size = strlen($message);
        socket_write($this->sockets[1], "PROCESSING\n$size:$message\n");
        $plugin = $this->getPlugin(true);
        $plugin
            ->expects($this->any())
            ->method('getSocket')
            ->willReturn($this->sockets[0]);

        $id = $plugin->run(function() { return "ok"; });
        $result = $message = $plugin->join($id);
        $this->assertEquals($data, $result);
    }

    public function testResultOnArrayJoin()
    {
        $data = "OK! " . microtime(true);
        $message = serialize($data);
        $size = strlen($message);
        socket_write($this->sockets[1], "PROCESSING\n$size:$message\n");
        $plugin = $this->getPlugin(true);
        $plugin
            ->expects($this->any())
            ->method('getSocket')
            ->willReturn($this->sockets[0]);

        $id = $plugin->run(function() { return "ok"; });
        $result = $message = $plugin->join([$id]);
        $this->assertEquals([$data], $result);
    }

    /**
     * @expectedException \RuntimeException
     * @expectedExceptionMessage Async call failed: request was corrupted
     */
    public function testSerializationErrorOnJoin()
    {
        socket_write($this->sockets[1], "PROCESSING\nCORRUPTED_REQUEST\n");
        $plugin = $this->getPlugin(true);
        $plugin
            ->expects($this->any())
            ->method('getSocket')
            ->willReturn($this->sockets[0]);

        $id = $plugin->run(function() { return "ok"; });
        $plugin->join($id);
    }

    /**
     * @expectedException \RuntimeException
     * @expectedExceptionMessage Async call failed: response is corrupted
     */
    public function testCorruptedResultOnJoin()
    {
        socket_write($this->sockets[1], "PROCESSING\naaaa\n");
        $plugin = $this->getPlugin(true);
        $plugin
            ->expects($this->any())
            ->method('getSocket')
            ->willReturn($this->sockets[0]);

        $id = $plugin->run(function() { return "ok"; });
        $plugin->join($id);
    }

    /**
     * @expectedException \RuntimeException
     * @expectedExceptionMessage Async call failed: response size is invalid
     */
    public function testCorruptedResultSizeOnJoin()
    {
        socket_write($this->sockets[1], "PROCESSING\naaaa12:aaa\n");
        $plugin = $this->getPlugin(true);
        $plugin
            ->expects($this->any())
            ->method('getSocket')
            ->willReturn($this->sockets[0]);

        $id = $plugin->run(function() { return "ok"; });
        $plugin->join($id);
    }
}