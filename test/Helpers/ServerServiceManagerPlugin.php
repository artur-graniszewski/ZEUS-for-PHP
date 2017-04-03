<?php

namespace ZeusTest\Helpers;

use Zend\EventManager\EventManagerInterface;
use Zend\EventManager\ListenerAggregateInterface;
use Zeus\ServerService\ManagerEvent;

class ServerServiceManagerPlugin implements ListenerAggregateInterface
{
    protected $eventsTriggered = [];

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
        $events->attach('*', function(ManagerEvent $event) {
            $this->eventsTriggered[] = $event->getName();
        });
    }

    /**
     * Detach all previously attached listeners
     *
     * @param EventManagerInterface $events
     * @return void
     */
    public function detach(EventManagerInterface $events)
    {
        // TODO: Implement detach() method.
    }

    /**
     * @return string[]
     */
    public function getTriggeredEvents()
    {
        return $this->eventsTriggered;
    }
}