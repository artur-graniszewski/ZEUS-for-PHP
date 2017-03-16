<?php

namespace ZeusTest;

use Zend\Http\Request;
use Zend\Http\Response;
use Zeus\ServerService\Http\Message\Message;
use Zeus\ServerService\Shared\React\ReactIoServer;

class ReactIoServerTest extends ReactServerTest
{
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
}