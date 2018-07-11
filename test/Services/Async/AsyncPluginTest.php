<?php

namespace ZeusTest\Services\Async;

use Exception;
use \PHPUnit\Framework\TestCase;
use Zend\ServiceManager\ServiceManager;
use Zeus\IO\SocketServer;
use Zeus\Kernel\System\Runtime;
use Zeus\ServerService\Async\AsyncPlugin;
use Zeus\ServerService\Async\Config;
use Zeus\ServerService\Async\Factory\AsyncPluginFactory;
use Zeus\ServerService\Async\Service;

/**
 * Class AsyncPluginTest
 * @package ZeusTest\Services\Async
 * @runTestsInSeparateProcesses true
 * @preserveGlobalState disabled
 */
class AsyncPluginTest extends \PHPUnit\Framework\TestCase
{
    /** @var SocketServer */
    protected $server;
    protected $port;
    protected $client;

    public function setUp()
    {
        Runtime::setShutdownHook(function() {
            return true;
        });
        $this->server = new SocketServer(0);
        $this->port = $this->server->getLocalPort();
        $this->server->setSoTimeout(1000);
        $this->server->setReuseAddress(true);

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
            $plugin->setJoinTimeout(2);

            return $plugin;
        }

        $config = $container->get('configuration');
        $config = new Config($config['zeus_process_manager']['services']['zeus_async']['service_settings']);

        $mockBuilder = $this->getMockBuilder(AsyncPlugin::class);
        $mockBuilder->setConstructorArgs([$config]);
        $mockBuilder->setMethods(['getSocket']);

        $plugin = $mockBuilder->getMock();
        $plugin->setJoinTimeout(2);

        return $plugin;
    }

    public function testPluginInstantiation()
    {
        $plugin = $this->getPlugin(false);
        $this->assertTrue($plugin instanceof AsyncPlugin);
    }

    /**
     * @expectedException \RuntimeException
     * @expectedExceptionMessage Async call failed: server reported bad request
     */
    public function testErrorHandlingOnRun()
    {
        fwrite($this->client, "BAD_REQUEST\n");
        $plugin = $this->getPlugin(true);
        $plugin
            ->expects($this->any())
            ->method('getSocket')
            ->willReturn($this->server->accept());

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
            ->willReturn($stream = $this->server->accept());

        try {
            $plugin->run(function () {
                return "ok";
            });
        } catch (Exception $e) {
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
            ->willReturn($this->server->accept());

        $id = $plugin->run(function() { return "ok"; });
        $this->assertInternalType('int', $id);
    }

    /**
     * @expectedException \RuntimeException
     * @expectedExceptionMessage Async call failed, no response from server
     */
    public function testOperationOnRealNotConnectedSocket()
    {
        $this->server->accept();
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
            ->willReturn($this->server->accept());

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
            ->willReturn($this->server->accept());

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
        stream_socket_sendto($this->client, "PROCESSING\n$size:$message\n");
        $plugin = $this->getPlugin(true);
        $plugin
            ->expects($this->any())
            ->method('getSocket')
            ->willReturn($this->server->accept());

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
            ->willReturn($this->server->accept());

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
            ->willReturn($this->server->accept());

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
            ->willReturn($this->server->accept());

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
            ->willReturn($stream = $this->server->accept());

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
            ->willReturn($stream = $this->server->accept());

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
            ->willReturn($this->server->accept());

        $id = $plugin->run(function() { return "ok"; });
        $plugin->join($id);
    }
}