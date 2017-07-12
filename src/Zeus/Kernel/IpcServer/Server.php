<?php

namespace Zeus\Kernel\IpcServer;

use Zend\EventManager\EventManager;
use Zend\EventManager\EventManagerInterface;
use Zend\EventManager\ListenerAggregateInterface;
use Zeus\Kernel\IpcServer\Adapter\IpcAdapterInterface;
use Zeus\Kernel\ProcessManager\SchedulerEvent;

class Server implements ListenerAggregateInterface
{
    protected $eventHandles;

    /** @var bool */
    protected $isConnected = false;

    /** @var IpcAdapterInterface */
    protected $ipc;

    /** @var EventManagerInterface */
    protected $events;

    protected $event;

    public function __construct()
    {
        $this->event = new IpcEvent();
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
        $this->eventHandles[] = $events->getSharedManager()->attach('*', SchedulerEvent::EVENT_SCHEDULER_LOOP, function() {
            $this->handleMessages();
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
     * Handles messages.
     *
     * @return $this
     */
    protected function handleMessages()
    {
        /** @var Message[] $messages */
        $this->ipc->useChannelNumber(0);

        $messages = $this->ipc->receiveAll();

        foreach ($messages as $message) {
            $event = new IpcEvent();
            $event->setName(IpcEvent::EVENT_MESSAGE_RECEIVED);
            $event->setParams($message);
            $event->setTarget($this);
            $this->getEventManager()->triggerEvent($event);
        }

        return $this;
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

    /**
     * @param IpcAdapterInterface $ipcAdapter
     * @return $this
     */
    public function setIpc(IpcAdapterInterface $ipcAdapter)
    {
        $this->ipc = $ipcAdapter;

        return $this;
    }

    /**
     * @return IpcAdapterInterface
     */
    public function getIpc()
    {
        return $this->ipc;
    }
}