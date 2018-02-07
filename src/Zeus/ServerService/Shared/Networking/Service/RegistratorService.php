<?php

namespace Zeus\ServerService\Shared\Networking\Service;

use Zend\EventManager\EventManagerInterface;
use Zend\Log\LoggerAwareTrait;
use Zeus\Exception\UnsupportedOperationException;
use Zeus\IO\Exception\IOException;
use Zeus\IO\Exception\SocketTimeoutException;
use Zeus\IO\SocketServer;
use Zeus\IO\Stream\AbstractStreamSelector;
use Zeus\IO\Stream\SelectionKey;
use Zeus\IO\Stream\Selector;
use Zeus\IO\Stream\SocketStream;
use Zeus\Kernel\Scheduler\AbstractEvent;
use Zeus\Kernel\Scheduler\SchedulerEvent;
use Zeus\Kernel\Scheduler\WorkerEvent;

use function stream_context_create;
use function stream_socket_client;
use function substr;
use function current;
use function explode;
use function in_array;

class RegistratorService
{
    use LoggerAwareTrait;

    const STATUS_WORKER_READY = 'ready';
    const STATUS_WORKER_LOCK = 'lock';
    const STATUS_WORKER_BUSY = 'busy';
    const STATUS_WORKER_GONE = 'gone';
    const STATUS_WORKER_FAILED = 'failed';
    const IPC_ADDRESS_EVENT_PARAM = 'zeusRegistratorIpcAddress';

    /** @var int[] */
    private $availableWorkers = [];

    /** @var string */
    private $backendHost = '127.0.0.1';

    /** @var SocketServer */
    private $registratorServer;

    /** @var SocketStream[] */
    private $registeredWorkerStreams = [];

    /** @var string */
    private $lastStatus;

    /** @var SocketStream */
    private $registratorStream;

    /** @var string */
    private $backendRegistrator = '';

    /** @var resource */
    private $streamContext;

    /** @var Selector */
    private $workerSelector;

    public function __construct()
    {
        $this->streamContext = stream_context_create([
            'socket' => [
                'tcp_nodelay' => true,
            ],
        ]);

        $this->workerSelector = new Selector();
    }

    public function attach(EventManagerInterface $events)
    {
        $events->attach(WorkerEvent::EVENT_CREATE, function (WorkerEvent $event) {
            $this->onWorkerCreate($event);
        }, 1000);

        $events->attach(WorkerEvent::EVENT_INIT, function (WorkerEvent $event) {
            $this->onWorkerInit($event);
        }, WorkerEvent::PRIORITY_REGULAR + 1);

//        $events->attach(SchedulerEvent::EVENT_LOOP, function (SchedulerEvent $event) use ($events) {
//            static $lasttime;
//            $now = time();
//            if ($lasttime !== $now) {
//                $lasttime = $now;
//                $uids = array_keys($this->availableWorkers);
//                sort($uids);
//                $this->getLogger()->debug("Available backend workers: " . json_encode($uids));
//            }
//        }, 1000);

        $events->attach(SchedulerEvent::EVENT_START, function($e) {
            $this->startRegistratorServer();
            $this->registerObservers($e); }, -9000);

        $events->attach(WorkerEvent::EVENT_EXIT, function (WorkerEvent $event) {
            if ($this->registratorStream) {
                try {
                    $this->registratorStream->flush();
                } catch (IOException $ex) {

                }

                if ($this->registratorStream->isReadable()) {
                    $this->registratorStream->shutdown(STREAM_SHUT_RD);
                }
                $this->registratorStream->close();
            }
        }, 1000);
    }

    public function setRegistratorAddress(string $address)
    {
        $this->backendRegistrator = $address;
    }

    public function getRegistratorAddress() : string
    {
        return $this->backendRegistrator;
    }

    private function onWorkerCreate(WorkerEvent $event)
    {
        if ($this->backendRegistrator) {
            $event->setParam(static::IPC_ADDRESS_EVENT_PARAM, $this->backendRegistrator);
        }
    }

    public function onWorkerInit(WorkerEvent $event)
    {
        if ($event->getParam(static::IPC_ADDRESS_EVENT_PARAM)) {
            $this->setRegistratorAddress($event->getParam(static::IPC_ADDRESS_EVENT_PARAM));
        }
    }

    private function getRegistratorStream()
    {
        if ($this->registratorStream) {
            return $this->registratorStream;
        }

        $socket = @stream_socket_client('tcp://' . $this->backendRegistrator, $errno, $errstr, 1000, STREAM_CLIENT_CONNECT, $this->streamContext);
        if (!$socket) {
            $this->getLogger()->err("Could not connect to leader: $errstr [$errno]");
            return false;
        }
        $registratorStream = new SocketStream($socket);
        $this->setStreamOptions($registratorStream);
        $this->registratorStream = $registratorStream;

        return $this->registratorStream;
    }

    public function notifyRegistrator(int $workerUid, int $port, string $status) : bool
    {
        if (!$this->backendRegistrator) {
            return false;
        }

        if ($this->lastStatus !== $status) {
            //$this->lastStatus = $status;
        } else {
            return true;
        }

        $registratorStream = $this->getRegistratorStream();

        try {
            $registratorStream->write("$status:$workerUid:$port!");
            while (!$registratorStream->flush()) {

            }
        } catch (IOException $ex) {
            $this->registratorStream = null;
            return $this->notifyRegistrator($workerUid, $port, $status);
        }

        return true;
    }

    public function getBackendWorker() : array
    {
        $readSelector = new Selector();
        $writeSelector = new Selector();
        $registratorStream = $this->getRegistratorStream();

        $readKey = $registratorStream->register($readSelector, SelectionKey::OP_READ);
        $writeKey = $registratorStream->register($writeSelector, SelectionKey::OP_WRITE);

        $uid = getmypid();
        $registratorStream->write(self::STATUS_WORKER_LOCK . ":$uid:1!");
        $flushed = false;
        $count = 5;
        while ($writeSelector->select(100) && !$flushed) {
            $flushed = $registratorStream->flush();
            if ($count === 0) {
                break;
            }

            $count--;
        };

        if (!$flushed) {
            $this->getLogger()->err("Unable to lock the backend worker: failed to send the data");
            return [0, 0];
        }

        $status = '';
        $timeout = 10;
        while (substr($status, -1) !== '@') {
            if ($readSelector->select(100)) {
                $buffer = $registratorStream->read();

                if ('' === $buffer) {
                    // EOF
                    $this->getLogger()->err("Unable to lock the backend worker: connection broken, read [$status]");
                    return [0, 0];
                }

                $status .= $buffer;
            }

            $timeout--;
            if ($timeout < 0) {
                $this->getLogger()->err("Unable to lock the backend worker: timeout detected, read: [$status]");
                return [0, 0];
            }
        }
        list($uid, $port) = explode(":", $status);

        return [(int) $uid, (int) $port];
    }

    private function setStreamOptions(SocketStream $stream)
    {
        try {
            $stream->setOption(SO_KEEPALIVE, 1);
            $stream->setOption(TCP_NODELAY, 1);
        } catch (UnsupportedOperationException $e) {
            // this may happen in case of disabled PHP extension, or definitely happen in case of HHVM
        }
    }

    private function checkWorkerOutput(AbstractStreamSelector $selector)
    {
        foreach ($selector->getSelectionKeys() as $selectionKey) {
            try {
                $this->checkBackendStatus($selectionKey);
            } catch (IOException $ex) {
                $stream = $selectionKey->getStream();
                $selectionKey->cancel();

                $stream->close();
            }
        }
    }

    private function addBackend(AbstractStreamSelector $selector)
    {
        try {
            $connection = $this->registratorServer->accept();
            $this->setStreamOptions($connection);
            $this->registeredWorkerStreams[] = $connection;
            $selectionKey = $connection->register($this->workerSelector, SelectionKey::OP_READ);
            $selectionKey->attach(new ReadBuffer());
            $this->setStreamOptions($connection);
        } catch (SocketTimeoutException $exception) {

        }
    }

    private function startRegistratorServer()
    {
        $server = new SocketServer();
        $server->setSoTimeout(0);
        $server->setTcpNoDelay(true);
        $server->bind($this->backendHost, 1000, 0);
        $this->registratorServer = $server;
        $this->getLogger()->debug("Registrator listening on: " . $this->registratorServer->getLocalAddress());
        $this->setRegistratorAddress($this->registratorServer->getLocalAddress());
    }

    private function registerObservers(AbstractEvent $event)
    {
        /** @var Selector $selector */
        $selector = new Selector();
        $this->registratorServer->getSocket()->register($selector, SelectionKey::OP_ACCEPT);
        $event->getScheduler()->observeSelector($selector, function() use ($selector) {$this->addBackend($selector);}, function() {}, 1000);
        $event->getScheduler()->observeSelector($this->workerSelector, function() {$this->checkWorkerOutput($this->workerSelector);}, function() {}, 1000);
    }

    private function checkBackendStatus(SelectionKey $selectionKey)
    {
        /** @var SocketStream $stream */
        $stream = $selectionKey->getStream();
        $data = $stream->read();
        $key = array_search($stream, $this->registeredWorkerStreams);
        /** @var ReadBuffer $buffer */
        $buffer = $selectionKey->getAttachment();

        if ($data === '') {
            unset ($this->registeredWorkerStreams[$key]);
            $selectionKey->cancel();
            try {
                $stream->flush();
            } catch (IOException $ex) {

            }

            if ($stream->isReadable()) {
                $stream->shutdown(STREAM_SHUT_RD);
            }
            $stream->close();

            return;
        }

        $buffer->append($data);

        if ($buffer->find('!') < 0) {
            return;
        }

        list($status, $uid, $port) = explode(":", $buffer->read());

        switch ($status) {
            case self::STATUS_WORKER_READY:
                $this->availableWorkers[$uid] = $port;
                //$this->getLogger()->debug("Worker $uid marked as ready");
                break;
//            case self::STATUS_WORKER_BUSY:
//                unset ($this->availableWorkers[$uid]);
//                //$this->getLogger()->debug("Worker $uid marked as busy");
//                break;

            case self::STATUS_WORKER_LOCK:
                $frontendUid = $uid;
                $uid = key($this->availableWorkers);
                $port = current($this->availableWorkers);
                if (!$uid) {
                    //$this->getLogger()->alert("No available backend workers: " . count($this->availableWorkers));
                    $stream->write("0:0@");
                } else {
                    unset ($this->availableWorkers[$uid]);
                    //$this->getLogger()->debug("Worker $uid at $port locked for frontend $frontendUid");
                    $stream->write("$uid:$port@");
                }

                do {
                    $done = $stream->flush();
                } while (!$done);

                break;

            case self::STATUS_WORKER_GONE:
                unset ($this->availableWorkers[$uid]);
                break;

            case self::STATUS_WORKER_FAILED:
                unset ($this->availableWorkers[$uid]);
                $this->getLogger()->err("Worker $uid marked as failed");
                break;

            default:
                $this->getLogger()->err("Unsupported status [$status] of a worker $uid");
                break;
        }
    }
}