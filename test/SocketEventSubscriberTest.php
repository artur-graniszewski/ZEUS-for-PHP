<?php

namespace ZeusTest;

use PHPUnit_Framework_TestCase;
use Zend\EventManager\EventManager;
use Zend\EventManager\SharedEventManager;
use Zeus\Kernel\ProcessManager\Process;
use Zeus\Kernel\ProcessManager\ProcessEvent;
use Zeus\Kernel\ProcessManager\SchedulerEvent;
use Zeus\ServerService\Async\Config;
use Zeus\ServerService\Shared\Networking\SocketMessageBroker;
use ZeusTest\Helpers\SocketTestMessage;
use ZeusTest\Helpers\ZeusFactories;

class SocketEventSubscriberTest extends PHPUnit_Framework_TestCase
{
    use ZeusFactories;

    /** @var SocketMessageBroker */
    protected $service;
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
        $server = $this->service->getServer();

        if ($server) {
            $server->close();
        }
    }

    public function testSubscriberRequestHandling()
    {
        $events = new EventManager(new SharedEventManager());
        $event = new SchedulerEvent();
        $event->setTarget($this->getScheduler(0));
        $process = new Process($event);
        $process->setProcessId(getmypid());
        $process->setConfig(new \Zeus\Kernel\ProcessManager\Config([]));
        $process->setIpc($event->getTarget()->getIpc());
        $process->attach($events);

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
        $this->service = $eventSubscriber = new SocketMessageBroker($this->config, $message);
        $eventSubscriber->attach($events);

        $events->attach(SchedulerEvent::EVENT_SCHEDULER_START, function(SchedulerEvent $event) use (& $schedulerStarted) {
            $event->stopPropagation(true);
        }, SchedulerEvent::PRIORITY_FINALIZE + 1);
        
        $events->attach(ProcessEvent::EVENT_PROCESS_INIT, function(ProcessEvent $event) use (& $schedulerStarted) {
            $event->stopPropagation(true);
        }, ProcessEvent::PRIORITY_FINALIZE + 1);

        $event->setName(SchedulerEvent::EVENT_SCHEDULER_START);
        $events->triggerEvent($event);

        $event = new ProcessEvent();
        $event->setTarget($process);
        $event->setName(ProcessEvent::EVENT_PROCESS_INIT);
        $event->setParams(['uid' => getmypid(), 'threadId' => 1, 'processId' => 1]);
        $events->triggerEvent($event);

        $client = stream_socket_client('tcp://localhost:' . $this->port);
        stream_set_blocking($client, false);

        $requestString = "GET / HTTP/1.0\r\nConnection: keep-alive\r\n\r\n";
        fwrite($client, $requestString);

        $event->setName(ProcessEvent::EVENT_PROCESS_LOOP);
        $events->triggerEvent($event);
        $events->triggerEvent($event);

        fclose($client);

        $event = new SchedulerEvent();
        $event->setName(ProcessEvent::EVENT_PROCESS_EXIT);
        $events->triggerEvent($event);

        $this->assertEquals($requestString, $received);
        $this->assertEquals(1, $steps, "Message should be fetched twice");
        $this->assertEquals(2, $heartBeats, "Heartbeat should be called twice");
    }

    public function testSubscriberErrorHandling()
    {
        $events = new EventManager(new SharedEventManager());
        $event = new SchedulerEvent();
        $event->setTarget($this->getScheduler(0));
        $process = new Process($event);
        $process->setConfig(new \Zeus\Kernel\ProcessManager\Config([]));
        $process->setIpc($event->getTarget()->getIpc());
        $process->attach($events);
        $process->setProcessId(getmypid());

        $received = null;
        $message = new SocketTestMessage(function($connection, $data) use (&$received) {
            throw new \RuntimeException("TEST");
        });
        $this->service = $eventSubscriber = new SocketMessageBroker($this->config, $message);
        $eventSubscriber->attach($events);

        $events->attach(SchedulerEvent::EVENT_SCHEDULER_START, function(SchedulerEvent $event) use (& $schedulerStarted) {
            $event->stopPropagation(true);
        }, SchedulerEvent::PRIORITY_FINALIZE + 1);

        $events->attach(ProcessEvent::EVENT_PROCESS_INIT, function(ProcessEvent $event) use (& $schedulerStarted) {
            $event->stopPropagation(true);
        }, ProcessEvent::PRIORITY_FINALIZE + 1);

        $event->setName(SchedulerEvent::EVENT_SCHEDULER_START);
        $events->triggerEvent($event);

        $event = new ProcessEvent();
        $event->setName(ProcessEvent::EVENT_PROCESS_INIT);
        $event->setTarget($process);

        $events->triggerEvent($event);

        $client = stream_socket_client('tcp://localhost:' . $this->port);
        stream_set_blocking($client, false);

        $requestString = "GET / HTTP/1.0\r\nConnection: keep-alive\r\n\r\n";
        fwrite($client, $requestString);

        $event->setName(ProcessEvent::EVENT_PROCESS_LOOP);
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