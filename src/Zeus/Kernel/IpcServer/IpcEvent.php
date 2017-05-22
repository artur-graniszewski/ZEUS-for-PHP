<?php

namespace Zeus\Kernel\IpcServer;

use Zend\EventManager\Event;
use Zeus\Kernel\ProcessManager\Process;

class IpcEvent extends Event
{
    const EVENT_MESSAGE_SEND = 'ipcMessageSend';
    const EVENT_MESSAGE_RECEIVED = 'ipcMessageReceived';

    protected $ipcInstance = '';

    /** @var Process */
    protected $source;

    public function setSource(Process $process)
    {
        $this->source = $process;
    }

    /**
     * @return Process
     */
    public function getTarget()
    {
        return parent::getTarget();
    }
}