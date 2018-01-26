<?php

namespace Zeus\Kernel;

use Zend\EventManager\EventManager;
use Zend\EventManager\EventManagerInterface;
use Zend\EventManager\ListenerAggregateInterface;
use Zeus\Kernel\IpcServer\IpcEvent;
use Zeus\Kernel\IpcServer\SocketIpc as IpcSocketStream;
use Zeus\Kernel\Scheduler\SchedulerEvent;
use Zeus\Kernel\Scheduler\WorkerEvent;
use Zeus\IO\Exception\SocketTimeoutException;
use Zeus\Exception\UnsupportedOperationException;
use Zeus\IO\Exception\IOException;
use Zeus\IO\SocketServer;
use Zeus\IO\Stream\SelectionKey;
use Zeus\IO\Stream\Selector;
use Zeus\IO\Stream\SocketStream;

use function count;
use function array_keys;
use function array_rand;
use function array_search;
use function microtime;
use function stream_socket_client;
use function get_called_class;

class IpcServer implements ListenerAggregateInterface
{
    private $ipcHost = '127.0.0.2';

    const AUDIENCE_ALL = 'aud_all';
    const AUDIENCE_ANY = 'aud_any';
    const AUDIENCE_SERVER = 'aud_srv';
    const AUDIENCE_SELECTED = 'aud_sel';
    const AUDIENCE_AMOUNT = 'aud_num';
    const AUDIENCE_SELF = 'aud_self';

    private $eventHandles;

    /** @var EventManagerInterface */
    private $events;

    /** @var SocketServer */
    private $ipcServer;

    /** @var IpcServer\SocketIpc */
    private $ipcClient;

    /** @var Selector */
    private $ipcSelector;

    /** @var SocketStream[] */
    private $ipcStreams = [];

    /** @var mixed[] */
    private $queuedMessages = [];

    /** @var float */
    private $lastTick;

    public function __construct()
    {
        $this->ipcSelector = new Selector();
    }

    /**
     * @param mixed $message
     * @param string $audience
     * @param int $number
     */
    public function send($message, string $audience = IpcServer::AUDIENCE_ALL, int $number = 0)
    {
        $this->ipcClient->send($message, $audience, $number);
    }

    private function startIpc()
    {
        $server = new SocketServer();
        $server->setTcpNoDelay(true);
        $server->setSoTimeout(0);
        $server->bind($this->ipcHost, 30000, 0);
        $this->ipcServer = $server;
    }

    private function addNewIpcClients()
    {
        try {
            while (true) {
                $ipcStream = $this->ipcServer->accept();
                // @todo: remove setBlocking(), now its needed in ZeusTest\SchedulerTest unit tests, otherwise they hang
                $ipcStream->setBlocking(true);
                $this->setStreamOptions($ipcStream);

                if (!$ipcStream->select(10)) {
                    return;
                }

                $uid = $ipcStream->read('!');
                $selectionKey = $this->ipcSelector->register($ipcStream, SelectionKey::OP_READ);
                $selectionKey->attach(new IpcSocketStream($ipcStream, $uid));
                $this->ipcStreams[(int)$uid] = $ipcStream;
            }
        } catch (SocketTimeoutException $exception) {

        }
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
    }

    private function registerIpc(int $ipcPort, int $uid)
    {
        $opts = [
            'socket' => [
                'tcp_nodelay' => true,
            ],
        ];

        $host = $this->ipcHost;
        $socket = @stream_socket_client("tcp://$host:$ipcPort", $errno, $errstr, 1, STREAM_CLIENT_CONNECT, stream_context_create($opts));

        if (!$socket) {
            throw new \RuntimeException("IPC connection failed: $errstr [$errno]");
        }

        $ipcStream = new SocketStream($socket);
        $ipcStream->setBlocking(false);
        $this->setStreamOptions($ipcStream);
        $ipcStream->write("$uid!");
        $ipcStream->flush();
        $this->ipcClient = new IpcSocketStream($ipcStream, $uid);
    }

    private function handleIpcMessages()
    {
        $selector = $this->ipcSelector;
        $event = new IpcEvent();
        $event->setName(IpcEvent::EVENT_HANDLING_MESSAGES);
        $event->setParam('selector', $selector);
        $event->setTarget($this);
        $this->getEventManager()->triggerEvent($event);

        $lastTick = $this->lastTick;
        $this->lastTick = microtime(true);
        $diff = microtime($this->lastTick) - $lastTick;

        $wait = (int) ($diff < 1 ? (1 - $diff) * 1000 : 100);
        if (!$selector->select($wait)) {
            return;
        }

        $keys = $selector->getSelectionKeys();
        $failed = 0; $processed = 0; $ignored = 0;
        foreach ($keys as $key) {
            /** @var SocketStream $stream */;
            $stream = $key->getStream();
            if ($stream->getLocalAddress() !== $this->ipcServer->getLocalAddress()) {
                $ignored++;
                $event = new IpcEvent();
                $event->setName(IpcEvent::EVENT_STREAM_READABLE);

                $event->setParam('selector', $selector);
                $event->setParam('selectionKey', $key);
                $event->setParam('stream', $stream);
                $event->setTarget($this);
                $this->getEventManager()->triggerEvent($event);

                continue;
            }

            /** @var IpcSocketStream $ipc */
            $ipc = $key->getAttachment();

            try {
                $messages = $ipc->readAll(true);
                $this->distributeMessages($messages);
                $processed++;
            } catch (IOException $exception) {
                $failed++;
                $selector->unregister($stream);

                $stream->close();
                unset($this->ipcStreams[array_search($stream, $this->ipcStreams)]);
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
    }

    private function onWorkerLoop(WorkerEvent $event)
    {
        if (!$this->ipcClient->isReadable()) {
            return;
        }

        $messages = $this->ipcClient->readAll(true);
        if (!$messages) {
            return;
        }

        foreach ($messages as $key => $payload) {
            $messages[$key]['aud'] = static::AUDIENCE_SELF;
        }

        $this->distributeMessages($messages);
    }

    /**
     * @param mixed $messages
     */
    private function distributeMessages(array $messages)
    {
        foreach ($messages as $payload) {
            $audience = $payload['aud'];
            $message = $payload['msg'];
            $senderId = $payload['sid'];
            $number = $payload['num'];

            $availableAudience = $this->ipcStreams;

            unset($availableAudience[0]);

            // sender is not an audience
            unset($availableAudience[$senderId]);

            // @todo: implement read confirmation?
            switch ($audience) {
                case self::AUDIENCE_ALL:
                    $cids = array_keys($availableAudience);
                    break;

                case self::AUDIENCE_ANY:
                    if (1 > count($availableAudience)) {
                        $this->queuedMessages[] = $payload;

                        continue 2;
                    }

                    $cids = [array_rand($availableAudience, 1)];

                    break;

                case self::AUDIENCE_AMOUNT:
                    if ($number > count($availableAudience)) {
                        $this->queuedMessages[] = $payload;

                        continue 2;
                    }

                    $cids = array_rand($availableAudience, $number);
                    if ($number === 1) {
                        $cids = [$cids];
                    }

                    break;

                case self::AUDIENCE_SELECTED:
                    $cids = [$this->ipcStreams[$number]];
                    break;

                case self::AUDIENCE_SERVER:
                case self::AUDIENCE_SELF:
                    $event = new IpcEvent();
                    $event->setName(IpcEvent::EVENT_MESSAGE_RECEIVED);
                    $event->setParams($message);
                    $event->setTarget($this);
                    $this->getEventManager()->triggerEvent($event);
                    $cids = [];

                    break;
                default:
                    $cids = [];
                    break;
            }

            if (!$cids) {
                continue;
            }

            foreach ($cids as $cid) {
                $ipcDriver = new IpcSocketStream($this->ipcStreams[$cid], $senderId);
                try {
                    $ipcDriver->send($message, $audience, $number);
                } catch (\Exception $exception) {
                    unset($this->ipcStreams[$cid]);
                    $this->ipcSelector->unregister($this->ipcStreams[$cid]);
                }
//                trigger_error("SENT $message FROM $senderId TO $cid ($audience)");
            }
        }
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
        $this->eventHandles[] = $sharedManager->attach('*', SchedulerEvent::INTERNAL_EVENT_KERNEL_LOOP, function() {
            $this->handleIpcMessages();
        }, SchedulerEvent::PRIORITY_REGULAR + 1);

        $this->eventHandles[] = $sharedManager->attach('*', SchedulerEvent::INTERNAL_EVENT_KERNEL_START, function() {
            $this->startIpc();
        }, SchedulerEvent::PRIORITY_REGULAR + 1);


        $sharedManager->attach('*', WorkerEvent::EVENT_LOOP, function(WorkerEvent $event) {
            $this->onWorkerLoop($event);
            }, -9000);

        $this->eventHandles[] = $sharedManager->attach('*', WorkerEvent::EVENT_INIT, function(WorkerEvent $event) {
            $ipcPort = $event->getParam('ipcPort');

            if (!$ipcPort) {
                return;
            }

            $uid = $event->getWorker()->getUid();
            $this->registerIpc($ipcPort, $uid);
            $event->getWorker()->setIpc($this);
        }, WorkerEvent::PRIORITY_INITIALIZE);

        $this->eventHandles[] = $sharedManager->attach('*', SchedulerEvent::EVENT_START, function(SchedulerEvent $event) use ($sharedManager, $priority) {
            $this->startIpc();
            $uid = $event->getParam('uid', 0);
            $this->registerIpc($this->ipcServer->getLocalPort(), $uid);
            $event->getScheduler()->setIpc($this);

            $this->eventHandles[] = $sharedManager->attach('*', WorkerEvent::EVENT_CREATE, function(WorkerEvent $event) {
                $event->setParam('ipcPort', $this->ipcServer->getLocalPort());
            });

            $this->eventHandles[] = $sharedManager->attach('*', SchedulerEvent::EVENT_LOOP, function() {
                $this->addNewIpcClients();
                $this->removeIpcClients();
                $this->handleIpcMessages();
            }, SchedulerEvent::PRIORITY_REGULAR + 1);
        }, 100000);
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

    public function setEventManager(EventManagerInterface $events)
    {
        $events->setIdentifiers(array(
            __CLASS__,
            get_called_class(),
        ));
        $this->events = $events;
    }

    public function getEventManager() : EventManagerInterface
    {
        if (null === $this->events) {
            $this->setEventManager(new EventManager());
        }

        return $this->events;
    }
}