<?php

namespace Zeus\ServerService\Plugin;

use RuntimeException;
use Zend\EventManager\EventManagerInterface;
use Zend\EventManager\ListenerAggregateInterface;
use Zeus\IO\Exception\IOException;
use Zeus\IO\SocketServer;
use Zeus\IO\Stream\AbstractSelectableStream;
use Zeus\IO\Stream\AbstractStream;
use Zeus\IO\Stream\SelectionKey;
use Zeus\IO\Stream\Selector;
use Zeus\IO\Stream\SocketStream;
use Zeus\Kernel\Scheduler;
use Zeus\Kernel\Scheduler\SchedulerEvent;
use Zeus\Kernel\Scheduler\Status\WorkerState;

use function stream_socket_client;
use function microtime;
use function json_encode;
use function json_decode;
use function sprintf;
use function getmypid;

/**
 * Class SchedulerStatus
 * @package Zeus\Kernel\Scheduler\Plugin
 * @internal
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

    private $options = [];

    public function __construct(array $options = [])
    {
        if (!isset($options['ipc_type']) || $options['ipc_type'] !== 'socket') {
            throw new RuntimeException("Invalid IPC type selected");
        }

        if (isset($options['listen_port']) && isset($options['listen_address'])) {
            $this->options = $options;

            return;
        }

        throw new RuntimeException("Listen port or address is missing");
    }

    public function getOptions() : array
    {
        return $this->options;
    }

    private function init(SchedulerEvent $event)
    {
        $scheduler = $event->getScheduler();
        $server = new SocketServer();
        $server->bind($this->options['listen_address'], null, $this->options['listen_port']);
        $this->statusServer = $server;

        $this->selector = new Selector();
        $server->getSocket()->register($this->selector, SelectionKey::OP_ACCEPT);

        $this->schedulerStatus = new WorkerState($scheduler->getConfig()->getServiceName());
        $this->startTime = microtime(true);
        $this->scheduler = $scheduler;

        $scheduler->observeSelector($this->selector, function() {
            $stream = $this->getClientStream();
            $this->sendStatus($stream);
        }, function() {}, 10000);
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
            /** @var SchedulerStatus $statusPlugin */
            $statusPlugin = $scheduler->getPluginByClass(static::class);
            $stream = $statusPlugin->getSchedulerStream();
            $response = $stream->read();
            $status = json_decode($response, true);

            return $status;
        } catch (IOException $ex) {
            return null;
        } catch (RuntimeException $ex) {
            return null;
        }
    }

    public function getClientStream() : AbstractSelectableStream
    {
        $stream = $this->statusServer->accept();

        return $stream;
    }

    public function getSchedulerStream() : AbstractSelectableStream
    {
        $options = $this->getOptions();
        $socketName = sprintf("tcp://%s:%d", $options['listen_address'], $options['listen_port']);
        $socket = @stream_socket_client($socketName, $errno, $errstr, 10);
        if (!$socket) {
            throw new RuntimeException("Connection failed");
        }

        $stream = new SocketStream($socket);
        $selector = new Selector();
        $stream->register($selector, SelectionKey::OP_READ);

        if (!$selector->select(5)) {
            throw new RuntimeException("Connection stalled");
        }

        return $stream;
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

    private function sendStatus(AbstractStream $stream)
    {
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