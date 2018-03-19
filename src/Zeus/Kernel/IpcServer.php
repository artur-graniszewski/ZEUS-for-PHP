<?php

namespace Zeus\Kernel;

use RuntimeException;
use Zend\EventManager\EventManagerAwareTrait;
use Zend\EventManager\EventManagerInterface;
use Zend\EventManager\ListenerAggregateInterface;
use Zeus\IO\Stream\AbstractStreamSelector;
use Zeus\IO\Stream\NetworkStreamInterface;
use Zeus\Kernel\IpcServer\IpcEvent;
use Zeus\Kernel\IpcServer\SocketIpc as IpcSocketStream;
use Zeus\Kernel\Scheduler\Reactor;
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
use function in_array;
use function array_keys;
use function array_merge;
use function array_rand;
use function array_search;
use function stream_socket_client;

class IpcServer implements ListenerAggregateInterface
{
    use EventManagerAwareTrait;

    private $ipcHost = 'tcp://127.0.0.2';

    private $isSchedulerRegistered = false;

    const AUDIENCE_ALL = 'aud_all';
    const AUDIENCE_ANY = 'aud_any';
    const AUDIENCE_SERVER = 'aud_srv';
    const AUDIENCE_SELECTED = 'aud_sel';
    const AUDIENCE_AMOUNT = 'aud_num';
    const AUDIENCE_SELF = 'aud_self';

    private $eventHandles;

    /** @var SocketServer */
    private $ipcServer;

    /** @var IpcServer\SocketIpc */
    private $ipcClient;

    /** @var Selector */
    private $ipcSelector;

    /** @var SocketStream[] */
    private $ipcStreams = [];

    /** @var SocketStream[] */
    private $inboundStreams = [];

    /** @var mixed[] */
    private $queuedMessages = [];

    private $isKernelRegistered = false;

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
        $server->getSocket()->register($this->ipcSelector, SelectionKey::OP_ACCEPT);
    }

    private function checkInboundConnections()
    {
        try {
            while (true) {
                $ipcStream = $this->ipcServer->accept();
                // @todo: remove setBlocking(), now its needed in ZeusTest\SchedulerTest unit tests, otherwise they hang
                $ipcStream->setBlocking(false);
                $this->setStreamOptions($ipcStream);

                $this->inboundStreams[] = $ipcStream;
                $selectionKey = $this->ipcSelector->register($ipcStream, SelectionKey::OP_READ);
                $selectionKey->attach(new IpcSocketStream($ipcStream));
            }
        } catch (SocketTimeoutException $exception) {

        }
    }

    private function addNewIpcClients(SelectionKey $key) : bool
    {
        $stream = $key->getStream();

        /** @var IpcSocketStream $buffer */
        $buffer = $key->getAttachment();
        $buffer->append($stream->read());

        $pos = $buffer->find('!');
        if (0 > $pos) {
            return false;
        }

        $data = $buffer->read($pos + 1);

        $uid = (int) $data;
        $buffer->setId($uid);
        $this->ipcStreams[(int) $uid] = $stream;
        unset ($this->inboundStreams[array_search($stream, $this->inboundStreams)]);

        return true;
    }

    private function setStreamOptions(NetworkStreamInterface $stream)
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
                if (!$ipcStream->isClosed()) {
                    continue;
                }
            } catch (IOException $exception) {

            }

            try {
                $ipcStream->close();
            } catch (IOException $exception) {

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
        $socket = @stream_socket_client("$host:$ipcPort", $errno, $errstr, 5, STREAM_CLIENT_CONNECT, stream_context_create($opts));

        if (!$socket) {
            throw new RuntimeException("IPC connection failed: $errstr [$errno]");
        }

        $ipcStream = new SocketStream($socket);
        $ipcStream->setBlocking(false);
        $this->setStreamOptions($ipcStream);
        $ipcStream->write("$uid!");
        do {$done = $ipcStream->flush(); } while (!$done);
        $this->ipcClient = new IpcSocketStream($ipcStream);
        $this->ipcClient->setId($uid);
    }

    private function handleIpcMessages(AbstractStreamSelector $selector)
    {
        $messages = [];

        $keys = $selector->getSelectionKeys();
        $failed = 0; $processed = 0;
        foreach ($keys as $key) {
            /** @var SocketStream $stream */;
            $stream = $key->getStream();

            if ($key->isAcceptable()) {
                $this->checkInboundConnections();
                continue;
            }

            /** @var IpcSocketStream $ipc */
            $ipc = $key->getAttachment();

            if (in_array($stream, $this->inboundStreams)) {
                try {
                    if (!$this->addNewIpcClients($key)) {
                        // request is incomplete
                        continue;
                    }

                    if ($ipc->peek(1) === '') {
                        // read was already performed and no more data's left in the buffer
                        // ignore this stream until next select
                        continue;
                    }

                } catch (IOException $exception) {
                    $failed++;
                    $selector->unregister($stream);

                    $stream->close();
                    unset($this->inboundStreams[array_search($stream, $this->inboundStreams)]);
                    continue;
                }
            }

            try {
                $messages = array_merge($messages, $ipc->readAll(true));
                $processed++;
            } catch (IOException $exception) {
                $failed++;
                $selector->unregister($stream);
                $stream->close();
                unset($this->ipcStreams[array_search($stream, $this->ipcStreams)]);
                continue;
            }
        }

        try {
            $this->distributeMessages($messages);
        } catch (IOException $exception) {
            // @todo: report such exception!
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
     * @param mixed[] $messages
     */
    private function distributeMessages(array $messages)
    {
        foreach ($messages as $payload) {
            $cids = [];
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

                    break;
                default:
                    $cids = [];
                    break;
            }

            if (!$cids) {
                continue;
            }

            foreach ($cids as $cid) {
                $ipcDriver = new IpcSocketStream($this->ipcStreams[$cid]);
                $ipcDriver->setId($senderId);
                try {
                    $ipcDriver->send($message, $audience, $number);

                } catch (IOException $exception) {
                    unset($this->ipcStreams[$cid]);
                    $this->ipcSelector->unregister($this->ipcStreams[$cid]);
                }
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
        $this->eventHandles[] = $sharedManager->attach('*', SchedulerEvent::INTERNAL_EVENT_KERNEL_LOOP, function(SchedulerEvent $event) {
            if (!$this->isKernelRegistered) {
                $this->setSelector($event->getScheduler()->getReactor());
                $this->isKernelRegistered = true;
            }
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

            $this->eventHandles[] = $sharedManager->attach('*', SchedulerEvent::EVENT_LOOP, function(SchedulerEvent $event) {
                if (!$this->isSchedulerRegistered) {
                    $this->setSelector($event->getScheduler()->getReactor());
                    $this->isSchedulerRegistered = true;
                }

                $this->removeIpcClients();

            }, SchedulerEvent::PRIORITY_REGULAR + 1);
        }, 100000);
    }

    private function setSelector(Reactor $scheduler)
    {
//        $scheduler->observeSelector($this->ipcSelector, function(AbstractStreamSelector $selector) use ($scheduler) {
//            $this->handleIpcMessages($selector);
//
//            $scheduler->observeSelector($this->ipcSelector, function(AbstractStreamSelector $selector) {
//                $this->handleIpcMessages($selector);
//            }, function() use ($scheduler) {
//                $this->setSelector($scheduler);
//
//            }, 1000);
//        }, function() {}, 1000);

        $scheduler->observe($this->ipcSelector, function(AbstractStreamSelector $selector) {
                $this->handleIpcMessages($selector);
            }, function() {


            }, 1000);

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
}