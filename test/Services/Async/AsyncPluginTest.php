<?php

namespace ZeusTest\Services\Async;

use PHPUnit_Framework_TestCase;
use Zend\ServiceManager\ServiceManager;
use Zeus\Kernel\Networking\SocketServer;
use Zeus\ServerService\Async\AsyncPlugin;
use Zeus\ServerService\Async\Config;
use Zeus\ServerService\Async\Factory\AsyncPluginFactory;
use Zeus\ServerService\Async\Service;

class AsyncPluginTest extends PHPUnit_Framework_TestCase
{
    /** @var SocketServer */
    protected $server;
    protected $port;
    protected $client;

    public function setUp()
    {
        $this->port = 9999;
        $this->server = new SocketServer($this->port);

        $this->client = stream_socket_client('tcp://localhost:' . $this->port);
        stream_set_blocking($this->client, false);
    }

    public function tearDown()
    {
        if ($this->server) {
            $this->server->close();
        }
        fclose($this->client);
    }

    /**
     * @param bool $asMock
     * @return AsyncPlugin|\PHPUnit_Framework_MockObject_MockObject
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
                        'service_adapter' => Service::class,
                        'service_settings' => [
                            'listen_port' => $this->port,
                            'listen_address' => '127.0.0.1',
                        ],
                    ]
                ]
            ]
        ]);

        if (!$asMock) {
            /** @var AsyncPlugin $plugin */
            $plugin = $factory($container, AsyncPlugin::class, []);

            return $plugin;
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
     * @expectedExceptionMessage Async call failed, server response: "BAD_REQUEST"
     */
    public function testErrorHandlingOnRun()
    {
        fwrite($this->client, "BAD_REQUEST\n");
        $plugin = $this->getPlugin(true);
        $plugin
            ->expects($this->any())
            ->method('getSocket')
            ->willReturn($this->server->accept(1));

        $plugin->run(function() { return "ok"; });
    }

    /**
     * @expectedException \RuntimeException
     * @expectedExceptionMessage Async call failed: async server is offline
     */
    public function testErrorHandlingOnRunWhenOffline()
    {
        $plugin = $this->getPlugin(false);
        $this->server->close();
        $this->server = null;

        $plugin->run(function() { return "ok"; });
    }

    public function testSocketIsClosedOnError()
    {
        fwrite($this->client, "BAD_REQUEST\n");
        $plugin = $this->getPlugin(true);
        $plugin
            ->expects($this->any())
            ->method('getSocket')
            ->willReturn($stream = $this->server->accept(1));

        try {
            $plugin->run(function () {
                return "ok";
            });
        } catch (\Exception $e) {
            $this->assertFalse($stream->isReadable(), "Socket should be closed after error");
            return;
        }

        $this->fail('No exception detected on error');
    }

    public function testProcessingOnRun()
    {
        fwrite($this->client, "PROCESSING\n");
        $plugin = $this->getPlugin(true);
        $plugin
            ->expects($this->any())
            ->method('getSocket')
            ->willReturn($this->server->accept(1));

        $plugin->run(function() { return "ok"; });
    }

    /**
     * @expectedException \RuntimeException
     * @expectedExceptionMessage Async call failed, no response from server
     */
    public function testOperationOnRealNotConnectedSocket()
    {
        $this->server->accept(1);
        $plugin = $this->getPlugin(false);

        $plugin->run(function() { return "ok"; });
    }

    public function testIsWorkingWhenNoDataOnStream()
    {
        fwrite($this->client, "PROCESSING\n");
        $plugin = $this->getPlugin(true);
        $plugin
            ->expects($this->any())
            ->method('getSocket')
            ->willReturn($this->server->accept(1));

        $id = $plugin->run(function() { return "ok"; });
        $isWorking = $plugin->isWorking($id);
        $this->assertTrue($isWorking, 'Callback should be reported as working');
    }

    public function testIsWorkingWhenDataOnStream()
    {
        fwrite($this->client, "PROCESSING\n");
        $plugin = $this->getPlugin(true);
        $plugin
            ->expects($this->any())
            ->method('getSocket')
            ->willReturn($this->server->accept(1));

        $id = $plugin->run(function() { return "ok"; });
        fwrite($this->client, "SOME DATA\n");
        $isWorking = $plugin->isWorking($id);
        $this->assertFalse($isWorking, 'Callback should be reported as not working anymore');
    }

    public function testResultOnJoin()
    {
        $data = "OK! " . microtime(true);
        $message = serialize($data);
        $size = strlen($message);
        fwrite($this->client, "PROCESSING\n$size:$message\n");
        $plugin = $this->getPlugin(true);
        $plugin
            ->expects($this->any())
            ->method('getSocket')
            ->willReturn($this->server->accept(1));

        $id = $plugin->run(function() { return "ok"; });
        $result = $message = $plugin->join($id);
        $this->assertEquals($data, $result);
    }

    /**
     * @expectedException \RuntimeException
     * @expectedExceptionMessage Join timeout encountered
     */
    public function testResultOnJoinTimeout()
    {
        fwrite($this->client, "PROCESSING\n");
        $plugin = $this->getPlugin(true);
        $plugin
            ->expects($this->any())
            ->method('getSocket')
            ->willReturn($this->server->accept(1));

        $this->assertGreaterThan(1, $plugin->getJoinTimeout());
        $plugin->setJoinTimeout(1);
        $this->assertEquals(1, $plugin->getJoinTimeout());
        $id = $plugin->run(function() { return "ok"; });
        $plugin->join($id);
    }

    public function testResultOnArrayJoin()
    {
        $data = "OK! " . microtime(true);
        $message = serialize($data);
        $size = strlen($message);
        fwrite($this->client, "PROCESSING\n$size:$message\n");
        $plugin = $this->getPlugin(true);
        $plugin
            ->expects($this->any())
            ->method('getSocket')
            ->willReturn($this->server->accept(1));

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
        fwrite($this->client, "PROCESSING\nCORRUPTED_REQUEST\n");
        $plugin = $this->getPlugin(true);
        $plugin
            ->expects($this->any())
            ->method('getSocket')
            ->willReturn($this->server->accept(1));

        $id = $plugin->run(function() { return "ok"; });
        $plugin->join($id);
    }

    /**
     * @expectedException \RuntimeException
     * @expectedExceptionMessage Async call failed: server connection lost
     */
    public function testTimeoutOnJoin()
    {
        fwrite($this->client, "PROCESSING\n");
        $plugin = $this->getPlugin(true);
        $plugin
            ->expects($this->any())
            ->method('getSocket')
            ->willReturn($stream = $this->server->accept(1));

        $id = $plugin->run(function() { return "ok"; });
        $stream->close();
        $plugin->join($id);
    }

    /**
     * @expectedException \RuntimeException
     * @expectedExceptionMessage Async call failed: response is corrupted
     */
    public function testCorruptedResultOnJoin()
    {
        fwrite($this->client, "PROCESSING\naaaa\n");
        $plugin = $this->getPlugin(true);
        $plugin
            ->expects($this->any())
            ->method('getSocket')
            ->willReturn($stream = $this->server->accept(1));

        $id = $plugin->run(function() { return "ok"; });
        $plugin->join($id);
    }

    /**
     * @expectedException \RuntimeException
     * @expectedExceptionMessage Async call failed: response size is invalid
     */
    public function testCorruptedResultSizeOnJoin()
    {
        fwrite($this->client, "PROCESSING\naaaa12:aaa\n");
        $plugin = $this->getPlugin(true);
        $plugin
            ->expects($this->any())
            ->method('getSocket')
            ->willReturn($this->server->accept(1));

        $id = $plugin->run(function() { return "ok"; });
        $plugin->join($id);
    }
}