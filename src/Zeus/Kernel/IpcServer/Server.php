<?php

namespace Zeus\Kernel\IpcServer;

use Zend\EventManager\EventManager;
use Zend\EventManager\EventManagerInterface;
use Zend\EventManager\ListenerAggregateInterface;
use Zeus\Kernel\ProcessManager\SchedulerEvent;
use Zeus\Kernel\ProcessManager\WorkerEvent;
use Zeus\Networking\Exception\SocketTimeoutException;
use Zeus\Networking\SocketServer;
use Zeus\Networking\Stream\Selector;
use Zeus\Networking\Stream\SocketStream;

class Server implements ListenerAggregateInterface
{
    protected $eventHandles;

    /** @var bool */
    protected $isConnected = false;

    /** @var EventManagerInterface */
    protected $events;

    /** @var SocketServer */
    protected $ipcServer;

    /** @var SocketStream */
    protected $ipcClient;

    /** @var Selector */
    protected $ipcSelector;

    /** @var SocketStream[] */
    protected $ipcStreams = [];

    /** @var mixed[] */
    protected $queuedMessages = [];

    public function __construct()
    {
        $this->ipcSelector = new Selector();
    }

    /**
     * @return $this
     */
    private function startIpc()
    {
        $server = new SocketServer();
        $server->setTcpNoDelay(true);
        $server->setSoTimeout(0);
        $server->bind('127.0.0.1', 30000, 0);
        $this->ipcServer = $server;

        return $this;
    }

    private function addNewIpcClients()
    {
        try {
            while (true) {
                $ipcStream = $this->ipcServer->accept();
                // @todo: remove setBlocking(), now its needed in ZeusTest\SchedulerTest unit tests, otherwise they hang
                $ipcStream->setBlocking(false);
                $ipcStream->setOption(SO_KEEPALIVE, 1);

                if (!$ipcStream->select(10)) {
                    return $this;
                }

                $uid = $ipcStream->read('!');

                $this->ipcSelector->register($ipcStream, Selector::OP_READ);
                $this->ipcStreams[(int)$uid] = $ipcStream;
            }
        } catch (SocketTimeoutException $exception) {

        }

        return $this;
    }

    /**
     * @return $this
     */
    private function removeIpcClients()
    {
        foreach ($this->ipcStreams as $uid => $ipcStream) {
            try {
                if (!$ipcStream->isClosed() && $ipcStream->isReadable() && $ipcStream->isWritable()) {
                    continue;
                }
            } catch (\Exception $exception) {

            }

            try {
                $ipcStream->close();
            } catch (\Exception $exception) {

            }

            unset ($this->ipcStreams[$uid]);
            $this->ipcSelector->unregister($ipcStream);
        }

        return $this;
    }

    /**
     * @param int $ipcPort
     * @param int $uid
     * @return $this
     */
    private function registerIpc($ipcPort, $uid)
    {
        $opts = [
            'socket' => [
                'tcp_nodelay' => true,
            ],
        ];

        $socket = @\stream_socket_client('tcp://127.0.0.1:' . $ipcPort, $errno, $errstr, 0, STREAM_CLIENT_CONNECT, stream_context_create($opts));

        if (!$socket) {
            throw new \RuntimeException("IPC connection failed");
        }

        \stream_set_blocking($socket, false);
        $ipcStream = new SocketStream($socket);
        $ipcStream->setOption(SO_KEEPALIVE, 1);
        $ipcStream->write("$uid!")->flush();
        $this->ipcClient = $ipcStream;

        return $this;
    }

    /**
     * @return $this
     */
    private function handleIpcMessages()
    {
        if (!$this->ipcSelector->select(1000)) {
            return $this;
        }

        $streams = $this->ipcSelector->getSelectedStreams(Selector::OP_READ);

        foreach ($streams as $stream) {
            $ipc = new \Zeus\Kernel\IpcServer\SocketStream($stream, 0);

            try {
                $messages = $ipc->readAll(true);

                $this->distributeMessages($messages);
            } catch (\Exception $exception) {
                $stream->close();
                unset($this->ipcStreams[array_search($stream, $this->ipcStreams)]);
                $this->ipcSelector->unregister($stream);
                continue;
            }
        }

        if (count($this->queuedMessages) > 0) {
            foreach ($this->queuedMessages as $id => $message) {
                $this->distributeMessages([$message]);
                unset ($this->queuedMessages[$id]);
            }

            if (count($this->queuedMessages) === 0) {
                $this->queuedMessages = [];
            }
        }

        return $this;
    }

    /**
     * @param $messages
     * @return $this
     */
    private function distributeMessages($messages)
    {
        $cids = [];
        foreach ($messages as $payload) {
            $audience = $payload['aud'];
            $message = $payload['msg'];
            $senderId = $payload['sid'];
            $number = $payload['num'];

            $availableAudience = $this->ipcStreams;
            // sender is not an audience
            unset($availableAudience[$senderId]);

            // @todo: implement read confirmation?
            switch ($audience) {
                case IpcDriver::AUDIENCE_ALL:
                    $cids = array_keys($availableAudience);
                    break;

                case IpcDriver::AUDIENCE_ANY:
                    if (1 > count($availableAudience)) {
                        $this->queuedMessages[] = $payload;

                        continue 2;
                    }
                    $cids = array_rand($availableAudience, 1);

                    break;

                case IpcDriver::AUDIENCE_AMOUNT:
                    if ($number > count($availableAudience)) {
                        $this->queuedMessages[] = $payload;

                        continue 2;
                    }
                    $cids = array_rand($availableAudience, $number);

                    break;

                case IpcDriver::AUDIENCE_SELECTED:
                    $cids = $this->ipcStreams[$number];
                    break;

                case IpcDriver::AUDIENCE_SERVER:
                    $event = new IpcEvent();
                    $event->setName(IpcEvent::EVENT_MESSAGE_RECEIVED);
                    $event->setParams($message);
                    $event->setTarget($this);
                    $this->getEventManager()->triggerEvent($event);

                    break;
                default:
                    $cids = [];
                    break;
            }

            if (!$cids) {
                continue;
            }

            foreach ($cids as $cid) {
                $ipcDriver = new \Zeus\Kernel\IpcServer\SocketStream($this->ipcStreams[$cid], $senderId);
                try {
                    $ipcDriver->send($message, $audience, $number);
                } catch (\Exception $exception) {
                    unset($this->ipcStreams[$cid]);
                    $this->ipcSelector->unregister($this->ipcStreams[$cid]);
                }
                //trigger_error("SENT $message FROM $senderId TO $cid ($audience)");
            }
        }

        return $this;
    }

    /**
     * Attach one or more listeners
     *
     * Implementors may add an optional $priority argument; the EventManager
     * implementation will pass this to the aggregate.
     *
     * @param EventManagerInterface $events
     * @param int $priority
     * @return void
     */
    public function attach(EventManagerInterface $events, $priority = 1)
    {
        $sharedManager = $events->getSharedManager();

        $this->eventHandles[] = $sharedManager->attach('*', WorkerEvent::EVENT_WORKER_INIT, function(WorkerEvent $event) {
            $ipcPort = $event->getParam('ipcPort');

            if (!$ipcPort) {
                return;
            }

            $uid = $event->getParam('threadId') > 1 ? $event->getParam('threadId') : $event->getParam('processId');
            $this->registerIpc($ipcPort, $uid);
            $event->getTarget()->setIpc(new \Zeus\Kernel\IpcServer\SocketStream($this->ipcClient, $uid));
        }, WorkerEvent::PRIORITY_INITIALIZE);

        $this->eventHandles[] = $sharedManager->attach('*', SchedulerEvent::EVENT_SCHEDULER_START, function() use ($sharedManager, $priority) {
            $this->startIpc();

            $this->eventHandles[] = $sharedManager->attach('*', SchedulerEvent::EVENT_WORKER_CREATE, function(SchedulerEvent $event) {
                $event->setParam('ipcPort', $this->ipcServer->getLocalPort());
            });

            $this->eventHandles[] = $sharedManager->attach('*', SchedulerEvent::EVENT_SCHEDULER_LOOP, function() {
                $this->addNewIpcClients();
                $this->removeIpcClients();
                $this->handleIpcMessages();
            }, SchedulerEvent::PRIORITY_REGULAR + 1);
        }, $priority);
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

    /**
     * @param EventManagerInterface $events
     * @return $this
     */
    public function setEventManager(EventManagerInterface $events)
    {
        $events->setIdentifiers(array(
            __CLASS__,
            get_called_class(),
        ));
        $this->events = $events;

        return $this;
    }

    /**
     * @return EventManagerInterface
     */
    public function getEventManager()
    {
        if (null === $this->events) {
            $this->setEventManager(new EventManager());
        }

        return $this->events;
    }
}