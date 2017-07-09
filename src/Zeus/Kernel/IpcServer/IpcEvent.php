<?php

namespace Zeus\Kernel\IpcServer;

use Zend\EventManager\Event;
use Zeus\Kernel\ProcessManager\Task;

class IpcEvent extends Event
{
    const EVENT_MESSAGE_SEND = 'ipcMessageSend';
    const EVENT_MESSAGE_RECEIVED = 'ipcMessageReceived';

    protected $ipcInstance = '';

    /** @var Task */
    protected $source;

    public function setSource(Task $process)
    {
        $this->source = $process;
    }

    /**
     * @return Task
     */
    public function getTarget()
    {
        return parent::getTarget();
    }
}