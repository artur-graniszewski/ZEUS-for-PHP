<?php

namespace Zeus\Kernel;

use RuntimeException;
use Zend\EventManager\EventManagerAwareInterface;
use Zend\EventManager\EventManagerAwareTrait;
use Zeus\IO\Stream\NetworkStreamInterface;
use Zeus\Kernel\IpcServer\Listener\KernelMessageBroker;
use Zeus\Kernel\IpcServer\Listener\SchedulerMessageBroker;
use Zeus\Kernel\IpcServer\Listener\WorkerMessageReader;
use Zeus\Kernel\IpcServer\SocketIpc as IpcSocketStream;
use Zeus\Kernel\Scheduler\SchedulerEvent;
use Zeus\Kernel\Scheduler\WorkerEvent;
use Zeus\Exception\UnsupportedOperationException;
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

class IpcServer implements EventManagerAwareInterface
{
    use EventManagerAwareTrait;

    private $ipcHost = 'tcp://127.0.0.2';

    const AUDIENCE_ALL = 'aud_all';
    const AUDIENCE_ANY = 'aud_any';
    const AUDIENCE_SERVER = 'aud_srv';
    const AUDIENCE_SELECTED = 'aud_sel';
    const AUDIENCE_AMOUNT = 'aud_num';
    const AUDIENCE_SELF = 'aud_self';

    private $eventHandles = [];

    /** @var SocketServer */
    private $ipcServer;

    /** @var IpcServer\SocketIpc */
    private $ipcClient;

    /** @var Selector */
    private $ipcSelector;

    public function __construct()
    {
        $this->ipcSelector = new Selector();
    }

    public function __destruct()
    {
        $events = $this->getEventManager();

        foreach ($this->eventHandles as $handle) {
            $events->detach($handle);
        }
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

    protected function attachDefaultListeners()
    {
        $events = $this->getEventManager();


        $this->eventHandles[] = $events->attach(SchedulerEvent::INTERNAL_EVENT_KERNEL_START, function() use ($events) {
            $this->startIpc();
            $this->eventHandles[] = $events->attach(SchedulerEvent::INTERNAL_EVENT_KERNEL_LOOP, new KernelMessageBroker($this->getEventManager(), $this->ipcSelector, $this->ipcServer), SchedulerEvent::PRIORITY_REGULAR + 1);

        }, SchedulerEvent::PRIORITY_REGULAR + 1);


        $this->eventHandles[] = $events->attach(WorkerEvent::EVENT_INIT, function(WorkerEvent $event) use ($events) {
            $ipcPort = $event->getParam('ipcPort');

            if (!$ipcPort) {
                return;
            }

            $uid = $event->getWorker()->getUid();
            $this->registerIpc($ipcPort, $uid);

            $this->eventHandles[] = $events->attach(WorkerEvent::EVENT_LOOP, new WorkerMessageReader($this->ipcClient, $this->getEventManager()), -9000);
        }, WorkerEvent::PRIORITY_INITIALIZE);

        $this->eventHandles[] = $events->attach(SchedulerEvent::EVENT_START, function(SchedulerEvent $event) use ($events) {
            $this->startIpc();
            $uid = $event->getParam('uid', 0);
            $this->registerIpc($this->ipcServer->getLocalPort(), $uid);

            $this->eventHandles[] = $events->attach(WorkerEvent::EVENT_CREATE, function(WorkerEvent $event) {
                $event->setParam('ipcPort', $this->ipcServer->getLocalPort());
            });

            $this->eventHandles[] = $events->attach(SchedulerEvent::EVENT_LOOP, new SchedulerMessageBroker($this->getEventManager(), $this->ipcSelector, $this->ipcServer), SchedulerEvent::PRIORITY_REGULAR + 1);
        }, 100000);
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

    private function setStreamOptions(NetworkStreamInterface $stream)
    {
        try {
            $stream->setOption(SO_KEEPALIVE, 1);
            $stream->setOption(TCP_NODELAY, 1);
        } catch (UnsupportedOperationException $e) {
            // this may happen in case of disabled PHP extension, or definitely happen in case of HHVM
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
        $socket = @stream_socket_client("$host:$ipcPort", $errno, $errstr, 100, STREAM_CLIENT_CONNECT, stream_context_create($opts));

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
}