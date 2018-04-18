<?php

namespace Zeus\Kernel\IpcServer\Listener;

use Zend\EventManager\EventManagerInterface;
use Zeus\Kernel\IpcServer;
use Zeus\Kernel\IpcServer\IpcEvent;
use Zeus\Kernel\Scheduler\WorkerEvent;
use Zeus\Kernel\IpcServer\SocketIpc;

class WorkerMessageReader
{
    /** @var SocketIpc */
    private $ipcClient;

    /** @var EventManagerInterface */
    private $eventManager;

    public function __construct(SocketIpc $ipcClient, EventManagerInterface $eventManager)
    {
        $this->ipcClient = $ipcClient;
        $this->eventManager = $eventManager;
    }

    public function __invoke(WorkerEvent $event)
    {
        if (!$this->ipcClient->isReadable()) {
            return;
        }

        $messages = $this->ipcClient->readAll(true);
        if (!$messages) {
            return;
        }

        foreach ($messages as $key => $payload) {
            $messages[$key]['aud'] = IpcServer::AUDIENCE_SELF;

            $event = new IpcEvent();
            $event->setName(IpcEvent::EVENT_MESSAGE_RECEIVED);
            $event->setParams($payload['msg']);
            $event->setTarget($this);
            $this->eventManager->triggerEvent($event);
        }
    }
}