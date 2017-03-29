<?php

namespace Zeus\Kernel\ProcessManager\Helper;

use Zend\EventManager\EventManagerInterface;
use Zend\EventManager\EventManager as ZendEventManager;

/**
 * Class EventManager
 * @package Zeus\Kernel\ProcessManager\Helper
 * @internal
 */
trait EventManager
{
    /**
     * @var EventManagerInterface
     */
    private $events;

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
            $this->setEventManager(new ZendEventManager());
        }

        return $this->events;
    }
}