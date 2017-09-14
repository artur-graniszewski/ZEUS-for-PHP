<?php

namespace Zeus\Kernel\IpcServer;

use Zend\EventManager\Event;
use Zeus\Kernel\ProcessManager\Worker;

class IpcEvent extends Event
{
    const EVENT_MESSAGE_SEND = 'ipcMessageSend';
    const EVENT_MESSAGE_RECEIVED = 'ipcMessageReceived';
    const EVENT_HANDLING_MESSAGES = 'ipcMessageHandling';
    const EVENT_STREAM_READABLE = 'ipcDataReceived';

    protected $ipcInstance = '';

    /** @var Worker */
    protected $source;

    public function setSource(Worker $process)
    {
        $this->source = $process;
    }

    /**
     * @return mixed
     */
    public function getTarget()
    {
        return parent::getTarget();
    }
}