<?php

namespace Zeus\ServerService;

use Zend\EventManager\Event;

class ManagerEvent extends Event
{
    const EVENT_MANAGER_INIT = 'managerInit';
    const EVENT_MANAGER_LOOP = 'managerLoop';
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

    /**
     * @param ServerServiceInterface $service
     * @return $this
     */
    public function setService(ServerServiceInterface $service)
    {
        $this->service = $service;

        return $this;
    }

    /**
     * Does the event represent an error?
     *
     * @return bool
     */
    public function isError()
    {
        return (bool) $this->getParam('error', false);
    }

    /**
     * Set the error message
     *
     * @param string $message
     * @return $this
     */
    public function setError($message)
    {
        $this->setParam('error', $message);

        return $this;
    }

    /**
     * Retrieve the error message
     *
     * @return string
     */
    public function getError()
    {
        return $this->getParam('error', '');
    }
}