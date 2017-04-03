<?php

namespace Zeus\ServerService;

use Zend\EventManager\Event;

class ManagerEvent extends Event
{
    const EVENT_MANAGER_INIT = 'managerInit';
    const EVENT_SERVICE_START = 'serviceStart';
    const EVENT_SERVICE_STOP = 'serviceStop';

    /** @var ServerServiceInterface */
    protected $service;

    /** @var Manager */
    protected $manager;

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
     * @return Manager
     */
    public function getManager()
    {
        return $this->manager;
    }

    /**
     * @param Manager $manager
     * @return $this
     */
    public function setManager($manager)
    {
        $this->manager = $manager;

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