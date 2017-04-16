<?php

namespace ZeusTest;

use Zend\Http\Request;
use Zend\Http\Response;
use Zeus\Kernel\ProcessManager\SchedulerEvent;
use Zeus\ServerService\Http\Message\Message;
use Zeus\ServerService\Shared\React\ReactEventSubscriber;
use Zeus\ServerService\Shared\React\ReactIoServer;

class ReactIoServerTest extends ReactServerTest
{
    public function setUp()
    {
        ob_start();
        parent::setUp();
    }

    public function tearDown()
    {
        parent::tearDown();
        ob_end_clean();
    }

    public function testNewConnection()
    {
        /** @var Request $request */
        $request = null;
        $server = new ReactIoServer(new Message(
            function(Request $_request) use (&$request) {
                $request = $_request;

                echo "OK!";
                return new Response();
            }),
            $this->server,
            $this->loop
        );

        $client = stream_socket_client('tcp://localhost:' . $this->port);
        $this->loop->tick();

        $requestString = "GET / HTTP/1.0\r\nConnection: keep-alive\r\n\r\n";
        $size = fwrite($client, $requestString);
        $this->loop->tick();

        $this->assertEquals(strlen($requestString), $size);
        $this->assertInstanceOf(Request::class, $request);
        $this->assertEquals('/', $request->getUri()->getPath());
        fclose($client);
    }

    /**
     * @expectedException \RuntimeException
     * @expectedExceptionMessage TEST EXCEPTION!
     */
    public function testErrorHandling()
    {
        /** @var Request $request */
        $request = null;
        $server = new ReactIoServer(new Message(
            function() {
                throw new \RuntimeException("TEST EXCEPTION!");
            }),
            $this->server,
            $this->loop
        );

        $client = stream_socket_client('tcp://localhost:' . $this->port);
        $this->loop->tick();

        $requestString = "GET / HTTP/1.0\r\nConnection: keep-alive\r\n\r\n";
        fwrite($client, $requestString);
        $this->loop->tick();
    }

    public function testHeartBeat()
    {
        $this->server->on('heartBeat', function() use (&$called) {
            $called++;
        });

        /** @var Request $request */
        $request = null;
        $server = new ReactIoServer(new Message(
            function(Request $_request) use (&$request) {
                $request = $_request;

                echo "OK!";
                return new Response();
            }),
            $this->server,
            $this->loop
        );

        $reactEventSubscriber = new ReactEventSubscriber($this->loop, $server);

        $client = stream_socket_client('tcp://localhost:' . $this->port);
        $this->loop->tick();

        $requestString = "GET / HTTP/1.0\r\nConnection: keep-alive\r\n\r\n";
        $size = fwrite($client, $requestString);

        $called = 0;

        $reactEventSubscriber->onTaskLoop(new SchedulerEvent());
        $reactEventSubscriber->onTaskLoop(new SchedulerEvent());

        $this->assertEquals(2, $called, 'Heartbeat should be performed 2 times');

        fclose($client);
    }
}