<?php

namespace Zeus\Kernel\IpcServer\Listener;

use Zend\EventManager\EventManagerInterface;
use Zeus\Kernel\IpcServer\IpcEvent;

class WorkerMessageSender
{
    /** @var IpcRegistrator */
    private $registrator;

    /** @var EventManagerInterface */
    private $eventManager;

    public function __construct(IpcRegistrator $registrator, EventManagerInterface $eventManager)
    {
        $this->registrator = $registrator;
        $this->eventManager = $eventManager;
    }

    public function __invoke(IpcEvent $event)
    {
        if ($event->getName() !== IpcEvent::EVENT_MESSAGE_SEND) {
            return;
        }

        $message = $event->getParam('message');
        $audience = $event->getParam('audience');
        $number = $event->getParam('number');
        $this->registrator->getClient()->send($message, $audience, $number);
    }
}