<?php

namespace Zeus\Kernel\IpcServer;

use Zend\EventManager\Event;
use Zeus\Kernel\IpcServer;
use Zeus\Kernel\Scheduler\Worker;

class IpcEvent extends Event
{
    const EVENT_MESSAGE_SEND = 'ipcMessageSend';
    const EVENT_MESSAGE_RECEIVED = 'ipcMessageReceived';

    protected $ipcInstance = '';

    /** @var Worker */
    protected $source;

    public function setSource(Worker $process)
    {
        $this->source = $process;
    }

    /**
     * @return IpcServer
     */
    public function getTarget()
    {
        return parent::getTarget();
    }
}