<?php

namespace Zeus\Kernel\IpcServer\Listener;

use Zend\EventManager\EventManagerInterface;
use Zeus\Exception\UnsupportedOperationException;
use Zeus\IO\Exception\IOException;
use Zeus\IO\Exception\SocketTimeoutException;
use Zeus\IO\SocketServer;
use Zeus\IO\Stream\AbstractStreamSelector;
use Zeus\IO\Stream\NetworkStreamInterface;
use Zeus\IO\Stream\SelectionKey;
use Zeus\IO\Stream\Selector;
use Zeus\IO\Stream\SocketStream;
use Zeus\Kernel\IpcServer;
use Zeus\Kernel\IpcServer\IpcEvent;
use Zeus\Kernel\IpcServer\SocketIpc;
use Zeus\Kernel\Scheduler\Reactor;
use Zeus\Kernel\Scheduler\SchedulerEvent;

use function in_array;
use function array_keys;
use function array_merge;
use function array_search;
use function array_rand;
use function count;

abstract class AbstractMessageBroker
{
    /** @var bool */
    private $isRegistered = false;

    /** @var Selector */
    private $ipcSelector;

    /** @var EventManagerInterface */
    private $eventManager;

    /** @var SocketStream[] */
    private $inboundStreams = [];

    /** @var mixed[] */
    private $queuedMessages = [];

    /** @var SocketStream[] */
    private $ipcStreams = [];

    /** @var SocketServer */
    private $ipcServer;

    public function __construct(EventManagerInterface $eventManager, Selector $ipcSelector, SocketServer $ipcServer)
    {
        $this->ipcSelector = $ipcSelector;
        $this->eventManager = $eventManager;
        $this->ipcServer = $ipcServer;
    }

    public function __invoke(SchedulerEvent $event)
    {
        if (!$this->isRegistered) {
            $this->setSelector($event->getScheduler()->getReactor());
            $this->isRegistered = true;
        }

        $this->removeIpcClients();
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

    private function setSelector(Reactor $reactor)
    {
        $reactor->observe($this->ipcSelector, function(AbstractStreamSelector $selector) {
            $this->handleIpcMessages($selector);
        }, function() {

        }, 1000
        );
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

    private function checkInboundConnections()
    {
        try {
            $ipcStream = $this->ipcServer->accept();
            // @todo: remove setBlocking(), now its needed in ZeusTest\SchedulerTest unit tests, otherwise they hang
            $ipcStream->setBlocking(false);
            $this->setStreamOptions($ipcStream);

            $this->inboundStreams[] = $ipcStream;
            $selectionKey = $this->ipcSelector->register($ipcStream, SelectionKey::OP_READ);
            $selectionKey->attach(new SocketIpc($ipcStream));
        } catch (SocketTimeoutException $exception) {
        }
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

            /** @var SocketIpc $ipc */
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

        if ($messages) {
            try {
                $this->distributeMessages($messages);
            } catch (IOException $exception) {
                // @todo: report such exception!
            }
        }

        if (count($this->queuedMessages) > 0) {
            foreach ($this->queuedMessages as $id => $message) {
                $this->distributeMessages([$message]);
                unset ($this->queuedMessages[$id]);
            }
        }
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
                case IpcServer::AUDIENCE_ALL:
                    $cids = array_keys($availableAudience);
                    break;

                case IpcServer::AUDIENCE_ANY:
                    if (1 > count($availableAudience)) {
                        $this->queuedMessages[] = $payload;

                        continue 2;
                    }

                    $cids = [array_rand($availableAudience, 1)];

                    break;

                case IpcServer::AUDIENCE_AMOUNT:
                    if ($number > count($availableAudience)) {
                        $this->queuedMessages[] = $payload;

                        continue 2;
                    }

                    $cids = array_rand($availableAudience, $number);
                    if ($number === 1) {
                        $cids = [$cids];
                    }

                    break;

                case IpcServer::AUDIENCE_SELECTED:
                    $cids = [$this->ipcStreams[$number]];
                    break;

                case IpcServer::AUDIENCE_SERVER:
                case IpcServer::AUDIENCE_SELF:
                    $event = new IpcEvent();
                    $event->setName(IpcEvent::EVENT_MESSAGE_RECEIVED);
                    $event->setParams($message);
                    $event->setTarget($this);
                    $this->eventManager->triggerEvent($event);

                    break;
                default:
                    $cids = [];
                    break;
            }

            if (!$cids) {
                continue;
            }

            foreach ($cids as $cid) {
                $ipcDriver = new SocketIpc($this->ipcStreams[$cid]);
                $ipcDriver->setId($senderId);
                try {
                    $ipcDriver->send($message, $audience, $number);

                } catch (IOException $exception) {
                    $this->ipcSelector->unregister($this->ipcStreams[$cid]);
                    unset($this->ipcStreams[$cid]);
                }
            }
        }
    }

    private function addNewIpcClients(SelectionKey $key) : bool
    {
        $stream = $key->getStream();

        /** @var SocketIpc $buffer */
        $buffer = $key->getAttachment();
        $buffer->append($stream->read());

        $pos = $buffer->find('!');
        if (0 > $pos) {
            return false;
        }

        $data = $buffer->read($pos + 1);

        $uid = (int) $data;
        $buffer->setId($uid);
        $this->ipcStreams[$uid] = $stream;
        unset ($this->inboundStreams[array_search($stream, $this->inboundStreams)]);

        return true;
    }
}