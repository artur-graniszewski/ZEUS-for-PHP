<?php

namespace Zeus\ServerService\Shared\Networking;

use Zend\EventManager\EventInterface;
use Zend\EventManager\EventManagerInterface;
use Zeus\Kernel\IpcServer\IpcEvent;
use Zeus\Kernel\ProcessManager\Scheduler;
use Zeus\Kernel\ProcessManager\Worker;
use Zeus\Networking\Exception\SocketException;
use Zeus\Networking\Exception\SocketTimeoutException;
use Zeus\Networking\Stream\Selector;
use Zeus\Networking\Stream\SocketStream;
use Zeus\Networking\SocketServer;

use Zeus\Kernel\ProcessManager\MultiProcessingModule\SharedAddressSpaceInterface;
use Zeus\Kernel\ProcessManager\MultiProcessingModule\SharedInitialAddressSpaceInterface;
use Zeus\Kernel\ProcessManager\WorkerEvent;
use Zeus\Kernel\ProcessManager\SchedulerEvent;
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
    protected $selector;

    /** @var SocketStream[] */
    protected $client = [];

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
        $events->attach('*', WorkerEvent::EVENT_WORKER_INIT, [$this, 'onWorkerStart']);
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
        $this->server->setSoTimeout(1);
        $this->server->bind($this->config->getListenAddress(), $backlog, $this->config->getListenPort());

        return $this;
    }

    /**
     * @return $this
     */
    protected function createIpcServer()
    {
        $this->ipcServer = new SocketServer();
        $this->ipcServer->setReuseAddress(false);
        $this->ipcServer->setSoTimeout(0);
        $this->ipcServer->bind('0.0.0.0', 1, 3333);

        return $this;
    }

    /**
     * @param WorkerEvent $event
     * @return $this
     */
    protected function createWorkerServer(WorkerEvent $event)
    {
        $this->workerServer = new SocketServer();
        $this->workerServer->setReuseAddress(true);
        $this->workerServer->setSoTimeout(1000);
        $this->workerServer->bind('0.0.0.0', 1, 0);
        $uid = $event->getTarget()->getProcessId();
        $port = $this->workerServer->getLocalPort();

        do {
            $client = @stream_socket_client('tcp://127.0.0.1:3333', $errno, $errstr, 1);
            if (!$client) {
                if (defined("DEBUG")) trigger_error(getmypid() . " CONNECTION WITH LEADER FAILED: $errstr");
                $this->createIpcServer();
                $this->isLeader = true;
                $this->workerServer->close();
                $this->workerServer = null;
                $event->getTarget()->setRunning();
                $this->createServer(1000);
                $this->selector = new Selector(Selector::OP_READ);
                if (defined("DEBUG")) trigger_error(getmypid() . " BECAME A LEADER");

                return $this;
            }

            stream_set_blocking($client, false);

            if (defined("DEBUG")) trigger_error(getmypid() . " NOTIFYING LEADER $uid:$port");
            stream_set_write_buffer($client, 0);
            $success = (bool) fwrite($client, "$uid:$port!");

            fflush($client);
//            fclose($client);
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

        $this->registerWorkers();
        $this->unregisterWorkers();
        $this->addClients();
        $this->handleClients();
        $this->disconnectClients();
    }

    protected function addClients()
    {
        try {
            while ($client = $this->server->accept()) {
                $this->bindToWorker($client);
            }
        } catch (SocketTimeoutException $exception) {

        }
    }

    protected function disconnectClients()
    {
        foreach ($this->downstream as $key => $stream) {
            if (!$stream->isReadable() || !$stream->isWritable()) {
                if (defined("DEBUG")) trigger_error(getmypid() . " DISCONNECTING WORKER FOR $key");
                unset($this->downstream[$key]);
                if (isset($this->client[$key])) {
                    $this->client[$key]->close();
                    unset($this->client[$key]);
                }
            }
        }

        foreach ($this->client as $key => $stream) {
            if (!$stream->isReadable() || !$stream->isWritable()) {
                if (defined("DEBUG")) trigger_error(getmypid() . " DISCONNECTING CLIENT BOUND TO $key");
                unset($this->client[$key]);
                if (isset($this->downstream[$key])) {
                    $this->downstream[$key]->close();
                    unset($this->downstream[$key]);
                }
            }
        }
    }

    protected function handleClients()
    {
        if (!$this->selector->select(1100)) {
            if (!$this->client) {
                usleep(10000);
            }
            return;
        }

        $streams = $this->selector->getSelectedStreams();

        foreach ($streams as $stream) {
            $output = null;
            $data = $stream->read();
            if (!isset($data[0])) {
                continue;
            }
            $key = array_search($stream, $this->client);

            if ($key !== false) {
                $output = $this->downstream[$key];
                $outputName = 'SERVER';
            }



            if (!$key && false !== ($key = array_search($stream, $this->downstream))) {
                $output = $this->client[$key];
                $outputName = 'CLIENT';
            }

            if ($output) {
                if (defined("DEBUG")) trigger_error(getmypid() . " WROTE TO $outputName $key");

                if ($output->isClosed()) {
                    trigger_error(getmypid() . " WRITE TO $outputName IS NOT POSSIBLE ANYMORE : " . json_encode([$output->isClosed(), $output->isReadable()]));
                }
                if (!$output->isWritable()) {
                    trigger_error(getmypid() . " WRITE TO $outputName IS NOT POSSIBLE YET : " . json_encode([$output->isClosed(), $output->isReadable()]));
                }
                $output->write($data)->flush();
            }
        }
    }

    protected function bindToWorker(SocketStream $client)
    {
        $downstream = null;
        foreach ($this->workers as $uid => $port) {
            if (isset($this->downstream[$uid])) {
                continue;
            }

            $socket = @stream_socket_client('tcp://127.0.0.1:' . $port, $errno, $errstr, 0);
            if (!$socket) {
                unset($this->workers[$uid]);
                continue;
            }
            stream_set_blocking($socket, false);

            if ($socket) {
                $downstream = new SocketStream($socket);
                $this->downstream[$uid] = $downstream;
                $this->client[$uid] = $client;
                $this->selector->register($client);
                $this->selector->register($downstream);
                if (defined("DEBUG")) trigger_error(getmypid() . " BOUND DOWNSTREAM $uid WITH CLIENT");

                break;
            }
        }

        if (!$downstream) {
            $downstreams = count($this->downstream);
            $workers = count($this->workers);
            throw new \RuntimeException("Connection pool exhausted [$downstreams downstreams active, $workers workers running]");
        }

        return $downstream;
    }

    protected function registerWorkers()
    {
        try {
            while ($connection = $this->ipcServer->accept()) {
                if ($connection->select(3)) {
                    $in = $connection->read('!');
                    list($uid, $port) = explode(":", $in);

                    $this->workers[$uid] = $port;
                    $this->ipc[$uid] = $connection;

                    if (defined("DEBUG")) trigger_error(getmypid() . " ATTACHED WORKER $uid ON PORT $port");

                    continue;
                }


            }
        } catch (SocketTimeoutException $exception) {

        }
    }

    protected function unregisterWorkers()
    {
        $count = count($this->ipc);

        //if (defined("DEBUG")) trigger_error(getmypid() ." CHECKING $count IPCs");

        foreach ($this->ipc as $uid => $ipc) {

            try {
                if (!$ipc->isClosed() && $ipc->isWritable()) {
                    // $ipc->write(' ')->flush();
                    continue;
                }
            } catch (SocketException $exception) {
            }

            if (defined("DEBUG")) trigger_error(getmypid() . " TERMINATING CONNECTION $uid");
            $ipc->close();
            if (isset($this->downstream[$uid])) {
                $downstream = $this->downstream[$uid];
                $downstream->close();
                unset ($this->downstream[$uid]);
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