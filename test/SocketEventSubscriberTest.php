<?php

namespace ZeusTest;

use PHPUnit_Framework_TestCase;
use Zend\EventManager\EventManager;
use Zeus\Kernel\ProcessManager\Process;
use Zeus\Kernel\ProcessManager\SchedulerEvent;
use Zeus\ServerService\Async\Config;
use Zeus\ServerService\Shared\Networking\SocketEventSubscriber;
use Zeus\ServerService\Shared\Networking\SocketServer;
use ZeusTest\Helpers\SocketTestMessage;
use ZeusTest\Helpers\ZeusFactories;

class SocketEventSubscriberTest extends PHPUnit_Framework_TestCase
{
    use ZeusFactories;

    /** @var SocketServer */
    protected $server;
    protected $port;

    public function setUp()
    {
        $config = new Config();
        $this->port = 7777;
        $config->setListenPort($this->port);
        $config->setListenAddress('0.0.0.0');
        $this->server = new SocketServer($config);
    }

    public function tearDown()
    {
        $this->server->stop();
    }

    public function testSubscriberRequestHandling()
    {
        $events = new EventManager();
        $event = new SchedulerEvent();
        $process = new Process($event);
        $process->setConfig(new \Zeus\Kernel\ProcessManager\Config([]));
        $event->setProcess($process);
        $process->setEventManager($events);

        $received = null;
        $steps = 0;
        $message = new SocketTestMessage(function($connection, $data) use (&$received, &$steps) {
            $received = $data;
            $steps ++;
        }, function($connection) use (& $heartBeats) {
            $heartBeats++;

            if ($heartBeats == 2) {
                $connection->close();
            }
        });
        $eventSubscriber = new SocketEventSubscriber($this->server, $message);
        $eventSubscriber->attach($events);

        $event->setName(SchedulerEvent::EVENT_SCHEDULER_START);
        $events->triggerEvent($event);

        $client = stream_socket_client('tcp://localhost:' . $this->port);
        stream_set_blocking($client, false);

        $requestString = "GET / HTTP/1.0\r\nConnection: keep-alive\r\n\r\n";
        fwrite($client, $requestString);

        $event->setName(SchedulerEvent::EVENT_PROCESS_LOOP);
        $events->triggerEvent($event);
        $events->triggerEvent($event);

        fclose($client);

        $event->setName(SchedulerEvent::EVENT_PROCESS_EXIT);
        $events->triggerEvent($event);

        $this->assertEquals($requestString, $received);
        $this->assertEquals(1, $steps, "Message should be fetched twice");
        $this->assertEquals(2, $heartBeats, "Heartbeat should be called twice");
    }

    public function testSubscriberErrorHandling()
    {
        $events = new EventManager();
        $event = new SchedulerEvent();
        $process = new Process($event);
        $process->setConfig(new \Zeus\Kernel\ProcessManager\Config([]));
        $event->setProcess($process);
        $process->setEventManager($events);

        $received = null;
        $message = new SocketTestMessage(function($connection, $data) use (&$received) {
            throw new \RuntimeException("TEST");
        });
        $eventSubscriber = new SocketEventSubscriber($this->server, $message);
        $eventSubscriber->attach($events);

        $event->setName(SchedulerEvent::EVENT_SCHEDULER_START);
        $events->triggerEvent($event);

        $client = stream_socket_client('tcp://localhost:' . $this->port);
        stream_set_blocking($client, false);

        $requestString = "GET / HTTP/1.0\r\nConnection: keep-alive\r\n\r\n";
        fwrite($client, $requestString);

        $event->setName(SchedulerEvent::EVENT_PROCESS_LOOP);
        $exception = null;
        try {
            $events->triggerEvent($event);
        } catch (\RuntimeException $exception) {
        }

        $this->assertTrue(is_object($exception), 'Exception should be raised');
        $this->assertInstanceOf(\RuntimeException::class, $exception, 'Correct exception should be raised');
        $this->assertEquals("TEST", $exception->getMessage(), 'Correct exception should be raised');
        $read = @stream_get_contents($client);
        $eof = feof($client);
        $this->assertEquals("", $read, 'Stream should not contain any message');
        $this->assertEquals(true, $eof, 'Client stream should not be readable when disconnected');

        fclose($client);
    }
}