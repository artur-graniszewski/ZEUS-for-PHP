<?php

namespace Zeus\ServerService\Shared\Networking;

use Zend\EventManager\EventManagerInterface;
use Zeus\Kernel\ProcessManager\Worker;
use Zeus\Networking\Exception\SocketException;
use Zeus\Networking\Exception\SocketTimeoutException;
use Zeus\Networking\Stream\Selector;
use Zeus\Networking\Stream\SocketStream;
use Zeus\Networking\SocketServer;
use Zeus\Kernel\ProcessManager\WorkerEvent;
use Zeus\ServerService\Shared\AbstractNetworkServiceConfig;

/**
 * Class SocketMessageBroker
 * @internal
 */
final class SocketMessageBroker
{
    protected $isBusy = false;

    /** @var bool */
    protected $isLeader = false;

    protected $oneServerPerWorker = false;

    /** @var SocketServer */
    protected $server;

    /** @var int */
    protected $lastTickTime = 0;

    /** @var MessageComponentInterface */
    protected $message;

    /** @var SocketStream */
    protected $connection;

    /** @var SocketServer */
    protected $ipcServer;

    /** @var SocketServer */
    protected $workerServer;

    /** @var SocketStream[] */
    protected $ipc = [];

    /** @var SocketStream[] */
    protected $downstream = [];

    /** @var int[] */
    protected $workers;

    /** @var Selector */
    protected $readSelector;

    /** @var Selector */
    protected $writeSelector;

    /** @var Selector */
    protected $ipcSelector;

    /** @var SocketStream[] */
    protected $client = [];

    /** @var SocketStream[] */
    protected $ipcClient;

    /** @var SocketStream[] */
    protected $connectionQueue = [];

    public function __construct(AbstractNetworkServiceConfig $config, MessageComponentInterface $message)
    {
        $this->config = $config;
        $this->message = $message;
    }

    /**
     * @param EventManagerInterface $events
     * @return $this
     */
    public function attach(EventManagerInterface $events)
    {
        $events = $events->getSharedManager();
        $events->attach('*', WorkerEvent::EVENT_WORKER_INIT, [$this, 'onWorkerStart'], WorkerEvent::PRIORITY_REGULAR);
        $events->attach('*', WorkerEvent::EVENT_WORKER_LOOP, [$this, 'onWorkerLoop']);
        $events->attach('*', WorkerEvent::EVENT_WORKER_LOOP, [$this, 'onLeaderLoop']);
        $events->attach('*', WorkerEvent::EVENT_WORKER_EXIT, [$this, 'onWorkerExit'], 1000);

        return $this;
    }

    /**
     * @param int $backlog
     * @return $this
     */
    protected function createServer(int $backlog)
    {
        $this->server = new SocketServer();
        $this->server->setReuseAddress(true);
        $this->server->setSoTimeout(0);
        $this->server->bind($this->config->getListenAddress(), $backlog, $this->config->getListenPort());

        return $this;
    }

    /**
     * @return $this
     */
    protected function createIpcServer()
    {
        $this->ipcServer = new SocketServer();
        $this->ipcServer->setReuseAddress(true);
        $this->ipcServer->setSoTimeout(0);
        $this->ipcServer->bind('0.0.0.0', 300, 3333);

        return $this;
    }

    /**
     * @param WorkerEvent $event
     * @return $this
     */
    protected function createWorkerServer(WorkerEvent $event)
    {
        $workerServer = new SocketServer();
        //$workerServer->setReuseAddress(true);
        $workerServer->setSoTimeout(100000);
        $workerServer->setTcpNoDelay(true);
        $workerServer->bind('0.0.0.0', 1, 0);
        $port = $workerServer->getLocalPort();

        $worker = $event->getTarget();
        $uid = $worker->getThreadId() > 1 ? $worker->getThreadId() : $worker->getProcessId();

        $this->workerServer = $workerServer;

        $opts = array(
            'socket' => array(
                'tcp_nodelay' => true,
            ),
        );

        do {
            $client = @stream_socket_client('tcp://127.0.0.1:3333', $errno, $errstr, 10, STREAM_CLIENT_CONNECT, stream_context_create($opts));
            if (!$client) {
                //trigger_error(getmypid() . " CONNECTION WITH LEADER FAILED: $errstr");
                $this->createIpcServer();
                $this->isLeader = true;
                $this->workerServer->close();
                $this->workerServer = null;
                $event->getTarget()->setRunning();
                $this->createServer(1000);
                $this->readSelector = new Selector();
                $this->ipcSelector = new Selector();
                //trigger_error(getmypid() . " BECAME A LEADER");

                return $this;
            }

            stream_set_blocking($client, false);
            $client = new SocketStream($client);

            trigger_error(getmypid() . " NOTIFYING LEADER $uid:$port");
            $client->write("$uid:$port!                                                                                                                        ")->flush();
            $success = true;
            $this->ipcClient = $client;

        } while (!$success);

        return $this;
    }

    /**
     */
    public function onWorkerStart(WorkerEvent $event)
    {
        $this->createWorkerServer($event);

        return;
    }

    /**
     * @param WorkerEvent $event
     * @throws \Throwable|\Exception
     * @throws null
     */
    public function onLeaderLoop(WorkerEvent $event)
    {
        if (!$this->isLeader) {
            return;
        }

        $now = microtime(true);
        while (true) {
            $this->registerWorkers();
            //trigger_error(sprintf("REGISTER WORKER :  %5f", microtime(true) - $now)); $now = microtime(true);
            $this->unregisterWorkers();
            //trigger_error(sprintf("UNREGISTER WORKER :  %5f", microtime(true) - $now)); $now = microtime(true);
            $this->addClients();
            //trigger_error(sprintf("ADD CLIENTS :  %5f", microtime(true) - $now)); $now = microtime(true);
            $this->disconnectClients();
            //trigger_error(sprintf("CLOSE CLIENTS :  %5f", microtime(true) - $now)); $now = microtime(true);
            $this->handleClients();
            //trigger_error(sprintf("HANDLE CLIENTS :  %5f", microtime(true) - $now)); $now = microtime(true);


            //trigger_error("\n\n\n\n\n");
        }
    }

    protected function addClients()
    {
        try {
            while (count($this->workers) > 0 && $client = $this->server->accept()) {
                $this->bindToWorker($client);
            }
            foreach ($this->connectionQueue as $key => $client) {
                unset($this->connectionQueue[$key]);
                $this->bindToWorker($client);
            }
        } catch (SocketTimeoutException $exception) {

        }
    }

    protected function disconnectClients()
    {
        foreach ($this->client as $key => $stream) {
            if (!$stream->isReadable() || !$stream->isWritable()) {
                $this->disconnectClient($key);
            }
        }

        foreach ($this->downstream as $key => $stream) {
            if (!$stream->isReadable() || !$stream->isWritable()) {
                $this->disconnectClient($key);
            }
        }
    }

    protected function handleClients()
    {
        if (!$this->client) {
            usleep(1000);

            return;
        }

        $now = microtime(true);
        do {
            if (!$this->readSelector->select(100)) {
                return;
            }

            $streamsToRead = $this->readSelector->getSelectedStreams(Selector::OP_READ);
            $streamsToWrite = $this->readSelector->getSelectedStreams(Selector::OP_WRITE);

            foreach ($streamsToRead as $stream) {
                $output = null;
                $key = array_search($stream, $this->client);

                if ($key !== false) {
                    $output = $this->downstream[$key];
                    $outputName = 'SERVER';
                } else if (false !== ($key = array_search($stream, $this->downstream))) {
                    $output = $this->client[$key];
                    $outputName = 'CLIENT';
                }

                try {
                    trigger_error("WRITTING");
                    $data = $stream->read();
                    trigger_error("READ $data!");
                    $output->write($data);
                    trigger_error("WROTE");
//                    if (in_array($output, $streamsToWrite) || $output->isClosed()) {
//                        $data = $stream->read();
//                        if (!$output->isClosed()) {
//                            $output->write($data);
//                        }
//                    }
                } catch (\Exception $exception) {
                    $this->disconnectClient($key);
                    break;
                }

            }
        } while ($streamsToRead && microtime(true) - $now < 0.01);
    }

    /**
     * @param int $key
     * @return $this
     */
    protected function disconnectClient($key)
    {
        if (isset($this->client[$key])) {
            $stream = $this->client[$key];
            if (!$stream->isClosed()) {
                $stream->close();
            }
            $this->readSelector->unregister($stream);
            unset ($this->client[$key]);
        }

        if (isset($this->downstream[$key])) {
            $stream = $this->downstream[$key];
            if (!$stream->isClosed()) {
                $stream->close();
            }
            $this->readSelector->unregister($stream);
            unset ($this->downstream[$key]);
        }

        return $this;
    }

    protected function bindToWorker(SocketStream $client)
    {
        $downstream = null;
        foreach ($this->workers as $uid => $port) {
            if (isset($this->downstream[$uid])) {
                continue;
            }

            $opts = array(
                'socket' => array(
                    'tcp_nodelay' => true,
                ),
            );

            $socket = @stream_socket_client('tcp://127.0.0.1:' . $port, $errno, $errstr, 0, STREAM_CLIENT_CONNECT, stream_context_create($opts));
            if (!$socket) {
                unset($this->workers[$uid]);
                continue;
            }
            stream_set_blocking($socket, false);

            if ($socket) {
                $downstream = new SocketStream($socket);
                $this->downstream[$uid] = $downstream;
                $this->client[$uid] = $client;
                $this->readSelector->register($downstream, Selector::OP_ALL);
                $this->readSelector->register($client, Selector::OP_ALL);

                break;
            }
        }

        if (!$downstream) {
            $downstreams = count($this->downstream);
            $workers = count($this->workers);
            trigger_error("Connection pool exhausted [$downstreams downstreams active, $workers workers running], connection queuing in effect");
            $this->connectionQueue[] = $client;
        }

        return $downstream;
    }

    protected function registerWorkers()
    {
        try {
            while ($connection = $this->ipcServer->accept()) {
                if ($connection->select(10)) {
                    $in = $connection->read('!');
                    list($uid, $port) = explode(":", $in);
                    //list($uid, $port) = [$uid, 80];

                    $this->workers[$uid] = $port;
                    $this->ipc[$uid] = $connection;
                    $this->ipcSelector->register($connection, Selector::OP_WRITE);

                    continue;
                } else {
                    trigger_error(getmypid() . " EMPTY CONNECTION!");
                }


            }
        } catch (SocketTimeoutException $exception) {

        }
    }

    protected function unregisterWorkers()
    {
        foreach ($this->ipc as $uid => $ipc) {
            try {
                $ipc->write(' ')->flush();

                if (!$ipc->isClosed() && $ipc->isReadable() && $ipc->isWritable()) {
                    continue;
                }
            } catch (SocketException $exception) {

            }

            $ipc->close();
            if (isset($this->downstream[$uid])) {
                $downstream = $this->downstream[$uid];
                $this->readSelector->unregister($downstream);
                if (!$downstream->isClosed()) {
                    $downstream->close();
                }
                unset ($this->downstream[$uid]);

                if (isset($this->client[$uid]) && !$this->client[$uid]->isClosed()) {
                    $this->bindToWorker($this->client[$uid]);
                } else {
                    unset ($this->client[$uid]);
                    $this->readSelector->unregister($this->client[$uid]);
                }
            }

            unset ($this->ipc[$uid]);
            unset ($this->workers[$uid]);
        }
    }


    /**
     * @param WorkerEvent $event
     * @throws \Throwable|\Exception
     * @throws null
     */
    public function onWorkerLoop(WorkerEvent $event)
    {
        if ($this->isLeader) {
            if ($this->isBusy) {
                return;
            }
            $event->getTarget()->setRunning();
            $this->isBusy = true;
            return;
        }

        $exception = null;

        if ($this->workerServer->isClosed()) {
            $this->createWorkerServer($event);
        }

        $this->ipcClient->read();

        try {
            if (!$this->connection) {
                try {
                    $connection = $this->workerServer->accept();
                    $event->getTarget()->getStatus()->incrementNumberOfFinishedTasks(1);
                    $event->getTarget()->setRunning();
                    if ($this->oneServerPerWorker) {
                        $this->workerServer->close();
                    }
                } catch (SocketTimeoutException $exception) {
                    $event->getTarget()->setWaiting();
                    if ($this->oneServerPerWorker) {
                        $this->workerServer->close();
                    }

                    return;
                }

                $this->connection = $connection;
                $this->message->onOpen($connection);
            }

            $this->connection->select(100);

            $data = '';
            while ($data !== false && $this->connection->isReadable()) {
                $data = $this->connection->read();
                if ($data !== false && $data !== '') {
                    $this->message->onMessage($this->connection, $data);
                }

                $this->onHeartBeat($event);
            }

            // nothing wrong happened, data was handled, resume main event
            if ($this->connection->isReadable() && $this->connection->isWritable()) {
                return;
            }
        } catch (\Throwable $exception) {
        }

        if ($this->connection) {
            if ($exception) {
                try {
                    $this->message->onError($this->connection, $exception);
                } catch (\Throwable $exception) {
                }
            }

            $this->connection->close();
            $this->connection = null;
        }

        $event->getTarget()->setWaiting();

        if ($exception) {
            throw $exception;
        }
    }

    public function onWorkerExit()
    {
        if ($this->oneServerPerWorker && !$this->server->isClosed()) {
            $this->server->close();
        }

        if ($this->workerServer && !$this->workerServer->isClosed()) {
            $this->workerServer->close();
            $this->workerServer = null;
        }

        if ($this->connection) {
            $this->connection->close();
            $this->connection = null;
        }
        $this->server = null;
    }

    /**
     * @return SocketServer
     */
    public function getServer()
    {
        return $this->server;
    }

    /**
     * @return $this
     */
    protected function onHeartBeat()
    {
        $now = time();
        if ($this->connection && $this->lastTickTime !== $now) {
            $this->lastTickTime = $now;
            if ($this->message instanceof HeartBeatMessageInterface) {
                $this->message->onHeartBeat($this->connection, []);
            }
        }

        return $this;
    }
}