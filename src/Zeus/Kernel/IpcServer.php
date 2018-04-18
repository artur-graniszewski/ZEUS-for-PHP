<?php

namespace Zeus\Kernel;

use Zend\EventManager\EventManagerAwareInterface;
use Zend\EventManager\EventManagerAwareTrait;
use Zeus\Kernel\IpcServer\IpcEvent;
use Zeus\Kernel\IpcServer\Listener\KernelMessageBroker;
use Zeus\Kernel\IpcServer\Listener\SchedulerMessageBroker;
use Zeus\Kernel\IpcServer\Listener\WorkerMessageSender;
use Zeus\Kernel\IpcServer\Listener\IpcRegistrator;
use Zeus\Kernel\Scheduler\SchedulerEvent;
use Zeus\Kernel\Scheduler\WorkerEvent;
use Zeus\IO\SocketServer;
use Zeus\IO\Stream\SelectionKey;
use Zeus\IO\Stream\Selector;

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
        $event = new IpcEvent();
        $event->setName(IpcEvent::EVENT_MESSAGE_SEND);
        $event->setParams([
            'audience' => $audience,
            'number' => $number,
            'message' => $message
        ]);

        $this->getEventManager()->triggerEvent($event);
    }

    protected function attachDefaultListeners()
    {
        $events = $this->getEventManager();

        $ipcRegistrator = new IpcRegistrator($this->getEventManager(), $this->ipcHost);

        $this->eventHandles[] = $events->attach(SchedulerEvent::INTERNAL_EVENT_KERNEL_START, function() use ($events) {
            $this->startIpc();
            $this->eventHandles[] = $events->attach(SchedulerEvent::INTERNAL_EVENT_KERNEL_LOOP, new KernelMessageBroker($this->getEventManager(), $this->ipcSelector, $this->ipcServer), SchedulerEvent::PRIORITY_REGULAR + 1);

        }, SchedulerEvent::PRIORITY_REGULAR + 1);


        $this->eventHandles[] = $events->attach(WorkerEvent::EVENT_INIT, $ipcRegistrator, WorkerEvent::PRIORITY_INITIALIZE);
        $this->eventHandles[] = $events->attach(SchedulerEvent::EVENT_START, $ipcRegistrator, SchedulerEvent::PRIORITY_INITIALIZE);
        $this->eventHandles[] = $events->attach(IpcEvent::EVENT_MESSAGE_SEND, new WorkerMessageSender($ipcRegistrator, $this->getEventManager()), WorkerEvent::PRIORITY_INITIALIZE);

        $this->eventHandles[] = $events->attach(SchedulerEvent::EVENT_START, function(SchedulerEvent $event) use ($events, $ipcRegistrator) {
            $this->startIpc();
            $event->setParam('ipcPort', $this->ipcServer->getLocalPort());
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
}