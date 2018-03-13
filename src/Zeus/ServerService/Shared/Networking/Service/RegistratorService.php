<?php

namespace Zeus\ServerService\Shared\Networking\Service;

use LogicException;
use Zend\Log\LoggerAwareTrait;
use Zeus\Exception\NoSuchElementException;
use Zeus\IO\Exception\IOException;
use Zeus\IO\Exception\SocketTimeoutException;
use Zeus\IO\Stream\SelectionKey;
use Zeus\IO\Stream\Selector;
use Zeus\IO\Stream\SocketStream;
use Zeus\Kernel\Scheduler\Reactor;

use function stream_socket_client;
use function substr;
use function explode;
use function array_search;

class RegistratorService extends AbstractService implements ServiceInterface
{
    use LoggerAwareTrait;

    const STATUS_WORKER_READY = 'ready';
    const STATUS_WORKER_LOCK = 'lock';
    const STATUS_WORKER_BUSY = 'busy';
    const STATUS_WORKER_GONE = 'gone';
    const STATUS_WORKER_FAILED = 'failed';
    const IPC_ADDRESS_EVENT_PARAM = 'zeusRegistratorIpcAddress';

    /** @var BackendPool */
    private $backendPool;

    /** @var string */
    private $registratorHost = 'tcp://127.0.0.1';

    /** @var SocketStream[] */
    private $registeredWorkerStreams = [];

    /** @var SocketStream */
    private $registratorStream;

    /** @var int */
    private $workerUid;

    public function __construct()
    {
        $this->setSelector($this->newSelector());
        $this->backendPool = new BackendPool();
    }

    public function setWorkerUid(int $uid)
    {
        $this->workerUid = $uid;
    }

    public function getWorkerUid(): int
    {
        return $this->workerUid;
    }

    public function setRegistratorAddress(string $address)
    {
        $this->registratorHost = $address;
    }

    public function getRegistratorAddress() : string
    {
        return $this->registratorHost;
    }

    public function isRegistered() : bool
    {
        return null !== $this->registratorStream && !$this->getRegistratorStream()->isClosed();
    }

    public function setRegistratorStream(SocketStream $registratorStream)
    {
        $this->setStreamOptions($registratorStream);
        $this->registratorStream = $registratorStream;
    }

    public function getRegistratorStream() : SocketStream
    {
        if (!$this->registratorStream) {
            throw new LogicException("No registrator stream available");
        }

        return $this->registratorStream;
    }

    public function register()
    {
        $socket = @stream_socket_client($this->getRegistratorAddress(), $errno, $errstr, 1000, STREAM_CLIENT_CONNECT, $this->getStreamContext());
        if (!$socket) {
            throw new IOException("Couldn't connect to registrator: $errstr", $errno);
        }
        $this->setRegistratorStream(new SocketStream($socket));
    }

    public function stopService()
    {
        if (!$this->isRegistered()) {
            throw new LogicException("Worker already unregistered");
        }

        $registrator = $this->getRegistratorStream();
        try {
            $registrator->flush();
        } catch (IOException $ex) {

        }

        if ($registrator->isReadable()) {
            $registrator->shutdown(STREAM_SHUT_RD);
        }
        $registrator->close();
    }

    public function notifyRegistrator(string $status, WorkerIPC $workerIPC) : bool
    {
        static $lastStatus = null;

        $address = $workerIPC->getAddress();
        $workerUid = $workerIPC->getUid();

//        if ($lastStatus === $status) {
//            return true;
//        }

        $registratorStream = $this->getRegistratorStream();

        try {
            $registratorStream->write("$status:$workerUid:$address!");
            while (!$registratorStream->flush()) {

            }
        } catch (IOException $ex) {
            $registratorStream->close();
            $this->register();

            return $this->notifyRegistrator($status, $workerIPC);
        }

        $lastStatus = $status;
        return true;
    }

    public function getBackendWorker() : WorkerIPC
    {
        $readSelector = new Selector();
        $writeSelector = new Selector();
        $registratorStream = $this->getRegistratorStream();

        $readKey = $registratorStream->register($readSelector, SelectionKey::OP_READ);
        $writeKey = $registratorStream->register($writeSelector, SelectionKey::OP_WRITE);

        $uid = $this->getWorkerUid();
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
            throw new IOException("Unable to lock the backend worker: failed to send the data");
        }

        $status = '';
        $timeout = 10;
        while (substr($status, -1) !== '@') {
            if ($readSelector->select(1000)) {
                $buffer = $registratorStream->read();

                if ('' === $buffer) {
                    // EOF
                    throw new IOException("Unable to lock the backend worker: connection broken, read [$status]");
                }

                $status .= $buffer;
            }

            $timeout--;
            if ($timeout < 0) {
                throw new IOException("Unable to lock the backend worker: timeout detected, read: [$status]");
            }
        }
        list($uid, $address) = explode(":", $status, 2);

        if ($uid == 0) {
            throw new NoSuchElementException("No backend worker available");
        }

        $workerIPC = new WorkerIPC((int) $uid, $address);
        return $workerIPC;
    }

    private function checkWorkersIPC()
    {
        foreach ($this->getSelector()->getSelectionKeys() as $selectionKey) {
            try {
                $this->handleWorkerIPC($selectionKey);
            } catch (IOException $ex) {
                $stream = $selectionKey->getStream();
                $selectionKey->cancel();

                $stream->close();
            }
        }
    }

    private function addBackend()
    {
        try {
            $connection = $this->getServer()->accept();
            $this->setStreamOptions($connection);
            $this->registeredWorkerStreams[] = $connection;
            $selectionKey = $connection->register($this->getSelector(), SelectionKey::OP_READ);
            $selectionKey->attach(new ReadBuffer());
            $this->setStreamOptions($connection);
        } catch (SocketTimeoutException $exception) {

        }
    }

    public function startService()
    {
        $server = $this->getServer();
        $server->bind($this->getRegistratorAddress(), 1000, 0);
        $this->setRegistratorAddress($server->getLocalAddress());
    }

    public function registerObservers(Reactor $reactor)
    {
        /** @var Selector $selector */
        $selector = $this->newSelector();
        $this->getServer()->getSocket()->register($selector, SelectionKey::OP_ACCEPT);
        $reactor->observe($selector, function() use ($selector) {$this->addBackend();}, function() {}, 1000);
        $reactor->observe($this->getSelector(), function() {$this->checkWorkersIPC();}, function() {}, 1000);
    }

    public function handleWorkerIPC(SelectionKey $selectionKey)
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

        $logger = $this->getLogger();

        list($status, $uid, $address) = explode(":", substr($buffer->read(), 0, -1), 3);
        $worker = new WorkerIPC((int) $uid, $address);
        $pool = $this->backendPool;

        switch ($status) {
            case self::STATUS_WORKER_READY:
                $pool->addWorker($worker);
                //$logger->debug("Worker $uid marked as ready at " . $worker->getAddress());
                break;

            case self::STATUS_WORKER_LOCK:
                try {
                    $worker = $pool->getAnyWorker();
                    $pool->removeWorker($worker);
                    $uid = $worker->getUid();
                    $address = $worker->getAddress();
                    $stream->write("$uid:$address@");
                } catch (NoSuchElementException $exception) {
                    $logger->alert("No backend workers available");
                    $stream->write("0:0@");
                }

                $count = 0;
                do {
                    $done = $stream->flush();
                    $count++;

                    if ($count > 5) {
                        $logger->warn("Gateway unresponsive");
                    }
                } while (!$done);

                break;

            case self::STATUS_WORKER_GONE:
                $pool->removeWorker($worker);
                break;

            case self::STATUS_WORKER_BUSY:
                break;

            case self::STATUS_WORKER_FAILED:
                $pool->removeWorker($worker);
                $logger->err("Worker $uid marked as failed");
                break;

            default:
                $logger->err("Unsupported status [$status] of a worker $uid");
                break;
        }
    }
}