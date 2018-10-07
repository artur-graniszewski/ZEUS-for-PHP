<?php

namespace ZeusTest\Services\Shared;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Zend\Log\Logger;
use Zend\Log\Writer\Noop;
use Zeus\Kernel\Scheduler\Command\StartScheduler;
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
use Zeus\Kernel\Scheduler\Command\InitializeWorker;
use Zeus\Kernel\Scheduler\Event\WorkerLoopRepeated;
use Zeus\Kernel\Scheduler\Event\WorkerExited;

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
        $config = new TestConfig([]);
        $config->setServiceName('test');
        $worker = new WorkerState("test");
        $worker->setProcessId(getmypid());
        $worker->setUid(getmypid());
        $logger = new Logger();
        $logger->addWriter(new Noop());

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
        $this->service = $eventSubscriber = new DirectMessageBroker($logger, $this->config, $message);
        $eventSubscriber->setLogger($logger);
        $eventSubscriber->attach($events);

        $events->attach(StartScheduler::class, function(SchedulerEvent $event) use (& $schedulerStarted) {
            $event->stopPropagation(true);
        }, SchedulerEvent::PRIORITY_FINALIZE + 1);
        
        $events->attach(InitializeWorker::class, function(WorkerEvent $event) use (& $schedulerStarted) {
            $event->stopPropagation(true);
        }, WorkerEvent::PRIORITY_FINALIZE + 1);

        $events->attach(WorkerExited::class, function(WorkerEvent $event) use (& $schedulerStarted) {
            $event->stopPropagation(true);
        }, WorkerEvent::PRIORITY_FINALIZE + 1);

        $event = new StartScheduler();
        $event->setScheduler($scheduler);
        $event->setTarget($scheduler);
        $events->triggerEvent($event);

        $event = new InitializeWorker();
        $event->setTarget($worker);
        $event->setWorker($worker);
        $event->setScheduler($scheduler);
        $event->setParams(['uid' => getmypid(), 'threadId' => 1, 'processId' => 1]);
        $events->triggerEvent($event);

        $host = $eventSubscriber->getBackend()->getServer()->getLocalAddress();
        $client = stream_socket_client("$host", $errno, $errstr, 2, STREAM_CLIENT_ASYNC_CONNECT);
        stream_set_blocking($client, false);

        $requestString = "GET / HTTP/1.0\r\nConnection: keep-alive\r\n\r\n";
        $wrote = stream_socket_sendto($client, $requestString);
        $this->assertEquals($wrote, strlen($requestString));

        $event = new WorkerLoopRepeated();
        $event->setTarget($worker);
        $event->setWorker($worker);
        $events->triggerEvent($event);
        $requestString2 = "GET / HTTP/1.0\r\nConnection: close\r\n\r\n";
        $wrote = stream_socket_sendto($client, $requestString2);
        $this->assertEquals($wrote, strlen($requestString2));
        $events->triggerEvent($event);
        $events->triggerEvent($event);

        fclose($client);

        $event = new WorkerExited();
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
        $config = new TestConfig([]);
        $config->setServiceName('test');
        $worker = new WorkerState("test");
        $worker->setProcessId(getmypid());
        $worker->setUid(getmypid());
        $logger = new Logger();
        $logger->addWriter(new Noop());

        $received = null;
        $message = new SocketTestMessage();
        $message->setMessageCallback(function($connection, $data) use (&$received) {
            throw new RuntimeException("TEST");
        });

        $message->setErrorCallback(function($connection, $exception) use (& $catchedException) {
            $catchedException = $exception;
        });
        $this->service = $eventSubscriber = new DirectMessageBroker($logger, $this->config, $message);
        $eventSubscriber->attach($events);

        $events->attach(StartScheduler::class, function(SchedulerEvent $event) use (& $schedulerStarted) {
            $event->stopPropagation(true);
        }, SchedulerEvent::PRIORITY_FINALIZE + 1);

        $events->attach(InitializeWorker::class, function(WorkerEvent $event) use (& $schedulerStarted) {
            $event->stopPropagation(true);
        }, WorkerEvent::PRIORITY_FINALIZE + 1);

        $event = new StartScheduler();
        $event->setTarget($scheduler);
        $event->setScheduler($scheduler);
        $events->triggerEvent($event);

        $event = new InitializeWorker();
        $event->setTarget($worker);
        $event->setWorker($worker);
        $event->setScheduler($scheduler);

        $events->triggerEvent($event);

        $host = $eventSubscriber->getBackend()->getServer()->getLocalAddress();
        $client = stream_socket_client("$host", $errno, $errstr, 2, STREAM_CLIENT_ASYNC_CONNECT);
        stream_set_blocking($client, false);

        $requestString = "GET / HTTP/1.0\r\nConnection: keep-alive\r\n\r\n";
        fwrite($client, $requestString);

        $event = new WorkerLoopRepeated();
        $event->setTarget($worker);
        $event->setWorker($worker);
        $event->setScheduler($scheduler);
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