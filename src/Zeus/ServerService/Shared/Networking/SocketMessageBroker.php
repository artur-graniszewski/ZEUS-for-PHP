<?php

namespace Zeus\ServerService\Shared\Networking;

use Zend\EventManager\EventManagerInterface;
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
    /** @var bool */
    protected $isBusy = false;

    /** @var bool */
    protected $isLeader = false;

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
    protected $workers = [];

    /** @var Selector */
    protected $selector;

    /** @var SocketStream[] */
    protected $client = [];

    /** @var SocketStream */
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
                $this->selector = new Selector();
                //trigger_error(getmypid() . " BECAME A LEADER");

                return $this;
            }

            stream_set_blocking($client, true);
            $client = new SocketStream($client);

            //trigger_error(getmypid() . " NOTIFYING LEADER $uid:$port");
            $client->write("$uid:$port!")->flush();
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

        while (true) {
            $this->registerWorkers();
            $this->unregisterWorkers();
            $this->addClients();
            $this->disconnectClients();
            $this->handleClients();
        }
    }

    /**
     * @return $this
     */
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

        return $this;
    }

    /**
     * @return $this
     */
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

        return $this;
    }

    /**
     * @return $this
     */
    protected function handleClients()
    {
        if (!$this->client) {
            \usleep(10000);

            return $this;
        }

        $now = microtime(true);
        do {
            if (!$this->selector->select(10000)) {
                return $this;
            }

            $streamsToRead = $this->selector->getSelectedStreams(Selector::OP_READ);
            $streamsToWrite = $this->selector->getSelectedStreams(Selector::OP_WRITE);

            foreach ($streamsToRead as $index => $input) {
                $output = null;
                $key = \array_search($input, $this->client);

                if ($key !== false) {
                    $output = $this->downstream[$key];
                    $outputName = 'SERVER';
                } else if (false !== ($key = \array_search($input, $this->downstream))) {
                    $output = $this->client[$key];
                    $outputName = 'CLIENT';
                }

                try {
                    if (in_array($output, $streamsToWrite) || $output->isClosed()) {
                        $data = $input->read();
                        //if (!$output->isClosed()) {
                            $output->write($data)->flush();
                        //}
                    }
                } catch (\Exception $exception) {
                    $this->disconnectClient($key);
                    continue 2;
                }

                if ($input->isClosed() || $output->isClosed()) {
                    break;
                }

            }
        } while ($streamsToRead && \microtime(true) - $now < 1);

        return $this;
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
                try {
                    $stream->close();
                } catch (\Exception $exception) {

                }
            }
            $this->selector->unregister($stream);
            unset ($this->client[$key]);
        }

        if (isset($this->downstream[$key])) {
            $stream = $this->downstream[$key];
            if (!$stream->isClosed()) {
                try {
                    $stream->close();
                } catch (\Exception $exception) {

                }
            }
            $this->selector->unregister($stream);
            unset ($this->downstream[$key]);
        }

        return $this;
    }

    /**
     * @param SocketStream $client
     * @return $this
     */
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
            stream_set_blocking($socket, true);

            if ($socket) {
                $downstream = new SocketStream($socket);
                $this->downstream[$uid] = $downstream;
                $this->client[$uid] = $client;
                $this->selector->register($downstream, Selector::OP_ALL);
                $this->selector->register($client, Selector::OP_ALL);

                break;
            }
        }

        if (!$downstream) {
            $downstreams = count($this->downstream);
            $workers = count($this->workers);
            trigger_error("Connection pool exhausted [$downstreams downstreams active, $workers workers running], connection queuing in effect");
            $this->connectionQueue[] = $client;
        }

        return $this;
    }

    /**
     * @return $this
     */
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

                    continue;
                } else {
                    trigger_error(getmypid() . " EMPTY CONNECTION!");
                }


            }
        } catch (SocketTimeoutException $exception) {

        }

        return $this;
    }

    /**
     * @return $this
     */
    protected function unregisterWorkers()
    {
        foreach ($this->ipc as $uid => $ipc) {
            try {
                $ipc->write(' ')->flush();

                if (!$ipc->isClosed() && $ipc->isReadable() && $ipc->isWritable()) {
                    continue;
                }
            } catch (\Exception $exception) {

            }

            try {
                $ipc->close();
            } catch (\Exception $exception) {

            }
            $this->disconnectClient($uid);

            unset ($this->ipc[$uid]);
            unset ($this->workers[$uid]);
        }

        return $this;
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
                } catch (SocketTimeoutException $exception) {
                    $event->getTarget()->setWaiting();

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

            if (!$this->connection->isClosed()) {
                $this->connection->flush();
                $this->connection->close();
            }
            $this->connection = null;
        }

        $event->getTarget()->setWaiting();

        if ($exception) {
            throw $exception;
        }
    }

    public function onWorkerExit()
    {
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