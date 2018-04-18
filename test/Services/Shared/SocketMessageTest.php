<?php

namespace ZeusTest\Services\Shared;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Zeus\Kernel\Scheduler\Config as TestConfig;
use Zeus\Kernel\Scheduler\Status\WorkerState;
use Zeus\Kernel\Scheduler\Worker;
use Zeus\Kernel\Scheduler\WorkerEvent;
use Zeus\Kernel\Scheduler\SchedulerEvent;
use Zeus\ServerService\Async\Config;
use Zeus\ServerService\Shared\Networking\DirectMessageBroker;
use Zeus\ServerService\Shared\Networking\GatewayMessageBroker;
use ZeusTest\Helpers\DummyMpm;
use ZeusTest\Helpers\SocketTestMessage;
use ZeusTest\Helpers\ZeusFactories;

/**
 * Class SocketMessageTest
 * @package ZeusTest\Services\Shared
 * @runTestsInSeparateProcesses true
 */
class SocketMessageTest extends TestCase
{
    use ZeusFactories;

    /** @var GatewayMessageBroker */
    protected $service;
    /** @var int */
    protected $port;
    /** @var Config */
    protected $config;

    public function setUp()
    {
        DummyMpm::getCapabilities()->setSharedInitialAddressSpace(true);
        $this->port = 7777;
        $this->config = new Config();
        $this->config->setListenAddress('0.0.0.0');
        $this->config->setListenPort($this->port);
    }

    public function testSubscriberRequestHandling()
    {
        $server = stream_socket_server('tcp://127.0.0.1:3333', $errno, $errstr);
        stream_set_blocking($server, false);
        $scheduler = $this->getScheduler(0);
        $events = $scheduler->getEventManager();
        $event = $scheduler->getSchedulerEvent();
        $config = new TestConfig([]);
        $config->setServiceName('test');
        $worker = new WorkerState("test");
        $worker->setProcessId(getmypid());
        $worker->setUid(getmypid());

        $received = [];
        $steps = 0;
        $message = new SocketTestMessage();

        $message->setMessageCallback(function($connection, $data) use (&$received, &$steps) {
            $received[] = $data;
            $steps ++;
        });

        $message->setHeartBeatCallback(function($connection) use (& $heartBeats) {
            $heartBeats++;

            if ($heartBeats == 5) {
                $connection->close();
            }
        });
        $this->service = $eventSubscriber = new DirectMessageBroker($this->config, $message, $scheduler->getLogger());
        $eventSubscriber->setLogger($event->getScheduler()->getLogger());
        $eventSubscriber->attach($events);

        $events->attach(SchedulerEvent::EVENT_START, function(SchedulerEvent $event) use (& $schedulerStarted) {
            $event->stopPropagation(true);
        }, SchedulerEvent::PRIORITY_FINALIZE + 1);
        
        $events->attach(WorkerEvent::EVENT_INIT, function(WorkerEvent $event) use (& $schedulerStarted) {
            $event->stopPropagation(true);
        }, WorkerEvent::PRIORITY_FINALIZE + 1);

        $events->attach(WorkerEvent::EVENT_EXIT, function(WorkerEvent $event) use (& $schedulerStarted) {
            $event->stopPropagation(true);
        }, WorkerEvent::PRIORITY_FINALIZE + 1);

        $event->setName(SchedulerEvent::EVENT_START);
        $events->triggerEvent($event);

        $event = new WorkerEvent();
        $event->setTarget($worker);
        $event->setWorker($worker);
        $event->setScheduler($scheduler);
        $event->setName(WorkerEvent::EVENT_INIT);
        $event->setParams(['uid' => getmypid(), 'threadId' => 1, 'processId' => 1]);
        $events->triggerEvent($event);

        $host = $eventSubscriber->getBackend()->getServer()->getLocalAddress();
        $client = stream_socket_client("$host", $errno, $errstr, 2, STREAM_CLIENT_ASYNC_CONNECT);
        stream_set_blocking($client, false);

        $requestString = "GET / HTTP/1.0\r\nConnection: keep-alive\r\n\r\n";
        $wrote = stream_socket_sendto($client, $requestString);
        $this->assertEquals($wrote, strlen($requestString));

        $event->setName(WorkerEvent::EVENT_LOOP);
        $event->setTarget($worker);
        $event->setWorker($worker);
        $events->triggerEvent($event);
        $requestString2 = "GET / HTTP/1.0\r\nConnection: close\r\n\r\n";
        $wrote = stream_socket_sendto($client, $requestString2);
        $this->assertEquals($wrote, strlen($requestString2));
        $events->triggerEvent($event);
        $events->triggerEvent($event);

        fclose($client);

        $event = new WorkerEvent();
        $event->setName(WorkerEvent::EVENT_EXIT);
        $event->setTarget($worker);
        $event->setWorker($worker);
        $event->setScheduler($scheduler);
        $events->triggerEvent($event);

        $this->assertEquals($requestString, $received[0]);
        $this->assertEquals(2, $steps, "Message should be fetched twice");

        // phpunit is broken on HHVM in case of assertGreatherThanOrEqual()
        // the error message is: __SystemLib\Error: Parameter $constraints is variadic and has a type constraint (PHPUnit\Framework\Constraint\Constraint); variadic params with type constraints are not supported in non-Hack files
        $this->assertTrue($heartBeats >= 1, "Heartbeat should be called at least once between requests");
    }

    public function testSubscriberErrorHandling()
    {
        $server = stream_socket_server('tcp://127.0.0.1:3334', $errno, $errstr);
        $scheduler = $this->getScheduler(0);
        $events = $scheduler->getEventManager();
        $event = $scheduler->getSchedulerEvent();
        $config = new TestConfig([]);
        $config->setServiceName('test');
        $worker = new WorkerState("test");
        $worker->setProcessId(getmypid());
        $worker->setUid(getmypid());

        $received = null;
        $message = new SocketTestMessage();
        $message->setMessageCallback(function($connection, $data) use (&$received) {
            throw new RuntimeException("TEST");
        });

        $message->setErrorCallback(function($connection, $exception) use (& $catchedException) {
            $catchedException = $exception;
        });
        $this->service = $eventSubscriber = new DirectMessageBroker($this->config, $message, $scheduler->getLogger());
        $eventSubscriber->attach($events);

        $events->attach(SchedulerEvent::EVENT_START, function(SchedulerEvent $event) use (& $schedulerStarted) {
            $event->stopPropagation(true);
        }, SchedulerEvent::PRIORITY_FINALIZE + 1);

        $events->attach(WorkerEvent::EVENT_INIT, function(WorkerEvent $event) use (& $schedulerStarted) {
            $event->stopPropagation(true);
        }, WorkerEvent::PRIORITY_FINALIZE + 1);

        $event->setName(SchedulerEvent::EVENT_START);
        $events->triggerEvent($event);

        $event = new WorkerEvent();
        $event->setName(WorkerEvent::EVENT_INIT);
        $event->setTarget($worker);
        $event->setWorker($worker);
        $event->setScheduler($scheduler);

        $events->triggerEvent($event);

        $host = $eventSubscriber->getBackend()->getServer()->getLocalAddress();
        $client = stream_socket_client("$host", $errno, $errstr, 2, STREAM_CLIENT_ASYNC_CONNECT);
        stream_set_blocking($client, false);

        $requestString = "GET / HTTP/1.0\r\nConnection: keep-alive\r\n\r\n";
        fwrite($client, $requestString);

        $event->setName(WorkerEvent::EVENT_LOOP);
        $exception = null;
        $events->triggerEvent($event);

        $this->assertTrue(is_object($catchedException), 'Exception should be raised');
        $this->assertInstanceOf(RuntimeException::class, $catchedException, 'Correct exception should be raised');
        $this->assertEquals("TEST", $catchedException->getMessage(), 'Correct exception should be raised');
        $read = @stream_get_contents($client);
        $eof = feof($client);
        $this->assertEquals("", $read, 'Stream should not contain any message');
        $this->assertEquals(true, $eof, 'Client stream should not be readable when disconnected');

        $server = false;
        fclose($client);
    }
}