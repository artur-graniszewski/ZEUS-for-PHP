<?php

namespace Zeus\ServerService\Shared\Networking\Service;

use Zend\EventManager\EventManagerInterface;
use Zeus\Exception\UnsupportedOperationException;
use Zeus\IO\Exception\IOException;
use Zeus\IO\Exception\SocketTimeoutException;
use Zeus\IO\SocketServer;
use Zeus\IO\Stream\SelectionKey;
use Zeus\IO\Stream\Selector;
use Zeus\IO\Stream\SocketStream;
use Zeus\Kernel\IpcServer;
use Zeus\Kernel\IpcServer\IpcEvent;
use Zeus\Kernel\Scheduler\SchedulerEvent;
use Zeus\Kernel\Scheduler\WorkerEvent;
use Zeus\ServerService\Shared\Networking\Message\RegistratorStartedMessage;
use Zeus\ServerService\Shared\Networking\SocketMessageBroker;

use function stream_context_create;
use function stream_socket_client;
use function substr;
use function explode;
use function in_array;

class RegistratorService
{
    const STATUS_WORKER_READY = 'ready';
    const STATUS_WORKER_LOCK = 'lock';
    const STATUS_WORKER_BUSY = 'busy';
    const STATUS_WORKER_GONE = 'gone';
    const STATUS_WORKER_FAILED = 'failed';

    /** @var SocketMessageBroker */
    private $messageBroker;

    /** @var int[] */
    private $availableWorkers = [];

    /** @var string */
    private $backendHost = '127.0.0.1';

    /** @var SocketServer */
    private $registratorServer;

    /** @var SocketStream[] */
    private $registeredWorkerStreams = [];

    /** @var bool */
    private $registratorLaunched = false;

    /** @var string */
    private $lastStatus;

    /** @var SocketStream */
    private $registratorStream;

    /** @var string */
    private $backendRegistrator = '';

    /** @var resource */
    private $streamContext;

    public function __construct(SocketMessageBroker $messageBroker)
    {
        $this->messageBroker = $messageBroker;

        $this->streamContext = stream_context_create([
            'socket' => [
                'tcp_nodelay' => true,
            ],
        ]);
    }

    public function attach(EventManagerInterface $events)
    {
        $events->attach(WorkerEvent::EVENT_CREATE, function (WorkerEvent $event) {
            $this->onWorkerCreate($event);
        }, 1000);

        $events->attach(WorkerEvent::EVENT_INIT, function (WorkerEvent $event) {
            $this->onWorkerInit($event);
        }, WorkerEvent::PRIORITY_REGULAR + 1);

        $events->getSharedManager()->attach(IpcServer::class, IpcEvent::EVENT_MESSAGE_RECEIVED, function (IpcEvent $event) {
            $this->onIpcMessage($event);
        }, WorkerEvent::PRIORITY_FINALIZE);

//        $events->attach(SchedulerEvent::EVENT_LOOP, function (SchedulerEvent $event) use ($events) {
//            static $lasttime;
//            $now = time();
//            if ($lasttime !== $now) {
//                $lasttime = $now;
//                $uids = array_keys($this->availableWorkers);
//                sort($uids);
//                $this->messageBroker->getLogger()->debug("Available backend workers: " . json_encode($uids));
//            }
//        }, 1000);
        $events->attach(SchedulerEvent::EVENT_LOOP, function (SchedulerEvent $event) use ($events) {
            if ($this->registratorLaunched === true) {
                return;
            }

            $this->startRegistratorServer();
            $this->registratorLaunched = true;
            $events->getSharedManager()->attach('*', IpcEvent::EVENT_HANDLING_MESSAGES, function($e) { $this->onIpcSelect($e); }, -9000);
            $events->getSharedManager()->attach('*', IpcEvent::EVENT_STREAM_READABLE, function($e) { $this->checkWorkerOutput($e); }, -9000);
            $event->getScheduler()->getIpc()->send(new RegistratorStartedMessage($this->registratorServer->getLocalAddress()), IpcServer::AUDIENCE_ALL);
            $event->getScheduler()->getIpc()->send(new RegistratorStartedMessage($this->registratorServer->getLocalAddress()), IpcServer::AUDIENCE_SELF);
        }, -1000);

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

    private function onIpcMessage(IpcEvent $event)
    {
        $message = $event->getParams();

        if ($message instanceof RegistratorStartedMessage) {
            /** @var RegistratorStartedMessage $message */
            $this->setRegistratorAddress($message->getIpcAddress());

            return;
        }
    }

    public function setRegistratorAddress(string $address)
    {
        $this->backendRegistrator = $address;
    }

    private function onWorkerCreate(WorkerEvent $event)
    {
        if ($this->backendRegistrator) {
            $event->setParam('leaderIpcAddress', $this->backendRegistrator);
        }
    }

    public function onWorkerInit(WorkerEvent $event)
    {
        if ($event->getParam('leaderIpcAddress')) {
            $this->setRegistratorAddress($event->getParam('leaderIpcAddress'));
        }
    }

    private function getRegistratorStream()
    {
        if ($this->registratorStream) {
            return $this->registratorStream;
        }

        $socket = @stream_socket_client('tcp://' . $this->backendRegistrator, $errno, $errstr, 1000, STREAM_CLIENT_CONNECT, $this->streamContext);
        if (!$socket) {
            $this->messageBroker->getLogger()->err("Could not connect to leader: $errstr [$errno]");
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
            $this->messageBroker->getLogger()->err("Unable to lock the backend worker: failed to send the data");
            return [0, 0];
        }

        $status = '';
        $timeout = 10;
        while (substr($status, -1) !== '@') {
            if ($readSelector->select(100)) {
                $buffer = $registratorStream->read();

                if ('' === $buffer) {
                    // EOF
                    $this->messageBroker->getLogger()->err("Unable to lock the backend worker: connection broken, read [$status]");
                    return [0, 0];
                }

                $status .= $buffer;
            }

            $timeout--;
            if ($timeout < 0) {
                $this->messageBroker->getLogger()->err("Unable to lock the backend worker: timeout detected, read: [$status]");
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

    private function addBackend(Selector $selector)
    {
        try {
            $connection = $this->registratorServer->accept();
            $this->setStreamOptions($connection);
            $this->registeredWorkerStreams[] = $connection;
            $selectionKey = $selector->register($connection, SelectionKey::OP_READ);
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
        $this->messageBroker->getLogger()->debug("Registrator listening on: " . $this->registratorServer->getLocalAddress());
    }

    private function onIpcSelect(IpcEvent $event)
    {
        /** @var Selector $selector */
        $selector = $event->getParam('selector');
        $selector->register($this->registratorServer->getSocket(), SelectionKey::OP_ACCEPT);
    }

    private function checkWorkerOutput(IpcEvent $event)
    {
        /** @var SocketStream $stream */
        $stream = $event->getParam('stream');

        /** @var Selector $selector */
        $selector = $event->getParam('selector');

        if ($stream === $this->registratorServer->getSocket()) {
            $this->addBackend($selector);
            return;
        }

        if (!in_array($stream, $this->registeredWorkerStreams)) {
            return;
        }

        $selectionKey = $event->getParam('selectionKey');

        try {
            $this->checkBackendStatus($selectionKey);
        } catch (IOException $ex) {
            $stream = $selectionKey->getStream();
            $selectionKey->cancel();
            $stream->close();
        }
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
                //$this->messageBroker->getLogger()->debug("Worker $uid marked as ready");
                break;
//            case self::STATUS_WORKER_BUSY:
//                unset ($this->availableWorkers[$uid]);
//                //$this->messageBroker->getLogger()->debug("Worker $uid marked as busy");
//                break;

            case self::STATUS_WORKER_LOCK:
                $frontendUid = $uid;
                $uid = key($this->availableWorkers);
                $port = current($this->availableWorkers);
                if (!$uid) {
                    //$this->messageBroker->getLogger()->alert("No available backend workers: " . count($this->availableWorkers));
                    $stream->write("0:0@");
                } else {
                    unset ($this->availableWorkers[$uid]);
                    //$this->messageBroker->getLogger()->debug("Worker $uid at $port locked for frontend $frontendUid");
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
                $this->messageBroker->getLogger()->err("Worker $uid marked as failed");
                break;

            default:
                $this->messageBroker->getLogger()->err("Unsupported status [$status] of a worker $uid");
                break;
        }
    }
}