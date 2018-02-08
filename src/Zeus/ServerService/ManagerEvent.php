<?php

namespace Zeus\ServerService;

use Zend\EventManager\Event;

class ManagerEvent extends Event
{
    const EVENT_MANAGER_INIT = 'managerInit';
    const EVENT_MANAGER_LOOP = 'managerLoop';
    const EVENT_MANAGER_STOP = 'managerStop';
    const EVENT_SERVICE_START = 'serviceStart';
    const EVENT_SERVICE_STOP = 'serviceStop';

    /** @var ServerServiceInterface */
    protected $service;

    /**
     * @return ServerServiceInterface
     */
    public function getService()
    {
        return $this->service;
    }

    public function setService(ServerServiceInterface $service)
    {
        $this->service = $service;
    }

    /**
     * Does the event represent an error?
     *
     * @return bool
     */
    public function isError() : bool
    {
        return (bool) $this->getParam('error', false);
    }

    /**
     * Set the error message
     *
     * @param string $message
     */
    public function setError(string $message = null)
    {
        $this->setParam('error', $message);
    }

    /**
     * Retrieve the error message
     *
     * @return string
     */
    public function getError() : string
    {
        return $this->getParam('error', '');
    }
}