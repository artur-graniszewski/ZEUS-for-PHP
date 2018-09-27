<?php

namespace Zeus\Kernel\IpcServer\Listener;

use Zend\EventManager\EventManagerInterface;
use Zeus\Exception\UnsupportedOperationException;
use Zeus\IO\Exception\IOException;
use Zeus\IO\Stream\NetworkStreamInterface;
use Zeus\IO\Stream\SocketStream;
use Zeus\Kernel\IpcServer\SocketIpc;
use Zeus\Kernel\Scheduler\SchedulerEvent;
use Zeus\Kernel\Scheduler\WorkerEvent;

use function stream_socket_client;
use function stream_context_create;
use Zeus\Kernel\Scheduler\Event\WorkerLoopRepeated;

class IpcRegistrator
{
    /** @var EventManagerInterface */
    private $eventManager;

    /** @var SocketIpc */
    private $ipcClient;

    /** @var string */
    private $ipcHost = '';

    public function __construct(EventManagerInterface $eventManager, string $ipcHost)
    {
        $this->eventManager = $eventManager;
        $this->ipcHost = $ipcHost;
    }

    public function getClient() : SocketIpc
    {
        return $this->ipcClient;
    }

    public function __invoke(SchedulerEvent $event)
    {
        $ipcPort = $event->getParam('ipcPort');

        if (!$ipcPort) {
            return;
        }

        $uid = $event->getWorker()->getUid();
        $this->registerIpc($ipcPort, $uid);

        $this->eventManager->attach(WorkerLoopRepeated::class, new WorkerMessageReader($this->ipcClient, $this->eventManager), -9000);
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
            throw new IOException("IPC connection failed: $errstr", $errno);
        }

        $ipcStream = new SocketStream($socket);
        $ipcStream->setBlocking(false);
        $this->setStreamOptions($ipcStream);
        $ipcStream->write("$uid!");        
        do {$done = $ipcStream->flush(); } while (!$done);
        $this->ipcClient = new SocketIpc($ipcStream);
        $this->ipcClient->setId($uid);
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
}