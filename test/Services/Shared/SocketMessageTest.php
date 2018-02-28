<?php

namespace ZeusTest\Services\Shared;

use \PHPUnit\Framework\TestCase;
use Zeus\Kernel\Scheduler\Config as TestConfig;
use Zeus\Kernel\Scheduler\Worker;
use Zeus\Kernel\Scheduler\WorkerEvent;
use Zeus\Kernel\Scheduler\SchedulerEvent;
use Zeus\ServerService\Async\Config;
use Zeus\ServerService\Shared\Networking\SocketMessageBroker;
use ZeusTest\Helpers\SocketTestMessage;
use ZeusTest\Helpers\ZeusFactories;

class SocketMessageTest extends \PHPUnit\Framework\TestCase
{
    use ZeusFactories;

    /** @var SocketMessageBroker */
    protected $service;
    /** @var int */
    protected $port;
    /** @var Config */
    protected $config;

    public function setUp()
    {
        $this->port = 7777;
        $this->config = new Config();
        $this->config->setListenAddress('0.0.0.0');
        $this->config->setListenPort($this->port);
    }

    public function tearDown()
    {
        try {
            $server = $this->service->getFrontend()->getFrontendServer();
        } catch (\LogicException $ex) {
            return;
        }
        if ($server && !$server->isClosed()) {
            $server->close();
        }
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
        $worker = new Worker();
        $worker->setProcessId(getmypid());
        $worker->setUid(getmypid());
        $worker->setConfig($config);
        $worker->setIpc($event->getScheduler()->getIpc());
        $worker->setLogger($event->getScheduler()->getLogger());
        $worker->setEventManager($events);

        $received = [];
        $steps = 0;
        $message = new SocketTestMessage(function($connection, $data) use (&$received, &$steps) {
            $received[] = $data;
            $steps ++;
        }, function($connection) use (& $heartBeats) {
            $heartBeats++;

            if ($heartBeats == 5) {
                $connection->close();
            }
        });
        $this->service = $eventSubscriber = new SocketMessageBroker($this->config, $message, $scheduler->getLogger());
        $eventSubscriber->getRegistrator()->setRegistratorAddress('127.0.0.1:3333');
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
        $event->setName(WorkerEvent::EVENT_INIT);
        $event->setParams(['uid' => getmypid(), 'threadId' => 1, 'processId' => 1]);
        $events->triggerEvent($event);

        $host = $eventSubscriber->getBackend()->getBackendServer()->getLocalAddress();
        $client = stream_socket_client("tcp://$host", $errno, $errstr, 2, STREAM_CLIENT_ASYNC_CONNECT);
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

        fclose($client);

        $event = new WorkerEvent();
        $event->setName(WorkerEvent::EVENT_EXIT);
        $event->setTarget($worker);
        $event->setWorker($worker);
        $events->triggerEvent($event);

        $this->assertEquals($requestString, $received[0]);
        $this->assertEquals(2, $steps, "Message should be fetched twice");

        // phpunit is broken on HHVM in case of assertGreatherThanOrEqual()
        // the error message is: __SystemLib\Error: Parameter $constraints is variadic and has a type constraint (PHPUnit\Framework\Constraint\Constraint); variadic params with type constraints are not supported in non-Hack files
        $this->assertTrue($heartBeats >= 1, "Heartbeat should be called at least once between requests");
    }

    public function testSubscriberErrorHandling()
    {
        $server = stream_socket_server('tcp://127.0.0.1:3333', $errno, $errstr);
        $scheduler = $this->getScheduler(0);
        $events = $scheduler->getEventManager();
        $event = $scheduler->getSchedulerEvent();
        $config = new TestConfig([]);
        $config->setServiceName('test');
        $worker = new Worker();
        $worker->setConfig($config);
        $worker->setIpc($event->getTarget()->getIpc());
        $worker->setLogger($event->getScheduler()->getLogger());
        $worker->setEventManager($events);
        $worker->setProcessId(getmypid());
        $worker->setUid(getmypid());

        $received = null;
        $message = new SocketTestMessage(function($connection, $data) use (&$received) {
            throw new \RuntimeException("TEST");
        });
        $this->service = $eventSubscriber = new SocketMessageBroker($this->config, $message, $scheduler->getLogger());
        $eventSubscriber->getRegistrator()->setRegistratorAddress('127.0.0.1:3333');
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

        $events->triggerEvent($event);

        $host = $eventSubscriber->getBackend()->getBackendServer()->getLocalAddress();
        $client = stream_socket_client("tcp://$host", $errno, $errstr, 2, STREAM_CLIENT_ASYNC_CONNECT);
        stream_set_blocking($client, false);

        $requestString = "GET / HTTP/1.0\r\nConnection: keep-alive\r\n\r\n";
        fwrite($client, $requestString);

        $event->setName(WorkerEvent::EVENT_LOOP);
        $exception = null;
        try {
            $events->triggerEvent($event);
        } catch (\Throwable $exception) {

        }

        $this->assertTrue(is_object($exception), 'Exception should be raised');
        $this->assertInstanceOf(\RuntimeException::class, $exception, 'Correct exception should be raised');
        $this->assertEquals("TEST", $exception->getMessage(), 'Correct exception should be raised');
        $read = @stream_get_contents($client);
        $eof = feof($client);
        $this->assertEquals("", $read, 'Stream should not contain any message');
        $this->assertEquals(true, $eof, 'Client stream should not be readable when disconnected');

        $server = false;
        fclose($client);
    }
}