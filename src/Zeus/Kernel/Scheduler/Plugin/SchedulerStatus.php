<?php

namespace Zeus\Kernel\Scheduler\Plugin;

use Zend\EventManager\EventManagerInterface;
use Zend\EventManager\ListenerAggregateInterface;
use Zeus\IO\Exception\IOException;
use Zeus\IO\SocketServer;
use Zeus\IO\Stream\SelectionKey;
use Zeus\IO\Stream\Selector;
use Zeus\IO\Stream\SocketStream;
use Zeus\Kernel\Scheduler;
use Zeus\Kernel\Scheduler\SchedulerEvent;
use Zeus\Kernel\Scheduler\Status\WorkerState;

/**
 * Class SchedulerStatus
 * @package Zeus\Kernel\Scheduler\Plugin
 * @deprecated
 */
class SchedulerStatus implements ListenerAggregateInterface
{
    /** @var mixed[] */
    private $eventHandles = [];

    /** @var Scheduler */
    private $scheduler;

    /** @var float */
    private $startTime;
    private $schedulerStatus;

    /** @var Selector */
    private $selector;

    /** @var SocketServer */
    private $statusServer;

    /** @var string */
    private $hostname = '127.0.0.4';

    /** @var int */
    private $port = 8000;

    protected function init(SchedulerEvent $event)
    {
        $scheduler = $event->getScheduler();
        $server = new SocketServer();
        $server->bind($this->hostname, null, $this->port);
        $this->statusServer = $server;

        $this->selector = new Selector();
        $server->getSocket()->register($this->selector, SelectionKey::OP_ACCEPT);
        $scheduler->observeSelector($this->selector, function() {
            $this->onSchedulerLoop();
        }, function() {}, 10000);

        $this->schedulerStatus = new WorkerState($event->getTarget()->getConfig()->getServiceName());
        $this->startTime = microtime(true);
        $this->scheduler = $scheduler;
    }

    /**
     * @param EventManagerInterface $events
     * @param int $priority
     */
    public function attach(EventManagerInterface $events, $priority = 100)
    {
        $this->eventHandles[] = $events->attach(SchedulerEvent::EVENT_LOOP, function(SchedulerEvent $e) {
            if (!$this->statusServer) {
                $this->init($e);
            }
        }, $priority);
    }

    /**
     * @param Scheduler $scheduler
     * @return mixed[]
     */
    public static function getStatus(Scheduler $scheduler)
    {
        try {
            /** @todo: ASAP! make it configurable per scheduler!!!! */
            $socket = @stream_socket_client("tcp://127.0.0.4:8000", $errno, $errstr, 10);
            if (!$socket) {
                return null;
            }
            $stream = new SocketStream($socket);
            $response = $stream->read();
            $status = json_decode($response, true);

            return $status;
        } catch (IOException $ex) {
            return null;
        }
    }

    /**
     * Detach all previously attached listeners
     *
     * @param EventManagerInterface $events
     * @return void
     */
    public function detach(EventManagerInterface $events)
    {
        foreach ($this->eventHandles as $handle) {
            $events->detach($handle);
        }
    }

    private function onSchedulerLoop()
    {
        $stream = $this->statusServer->accept();
        $scheduler = $this->scheduler;

        $payload = [
            'uid' => getmypid(),
            'logger' => __CLASS__,
            'process_status' => $scheduler->getWorkers()->toArray(),
            'scheduler_status' => $scheduler->getStatus()->toArray(),
        ];

        $payload['scheduler_status']['total_traffic'] = 0;
        $payload['scheduler_status']['start_timestamp'] = $this->startTime;

        // @todo: make it non-blocking somehow
        try {
            $stream->write(json_encode($payload));
            do {
            } while (!$stream->flush());
        } catch (IOException $ex) {
            $stream->close();
        }
    }
}