<?php

namespace Zeus\ServerService\Http;

class Config implements HttpConfigInterface
{
    /** @var int */
    private $keepAliveTimeout = 5;

    /** @var int */
    private $maxKeepAliveRequests = 100;

    /** @var bool */
    private $isKeepAliveEnabled = true;

    /** @var int */
    private $listenPort = 0;

    /** @var string */
    private $listenAddress = '';

    /**
     * Config constructor.
     * @param mixed[] $settings
     */
    public function __construct($settings = null)
    {
        if (isset($settings['listen_port'])) {
            $this->setListenPort($settings['listen_port']);
        }

        if (isset($settings['listen_address'])) {
            $this->setListenAddress($settings['listen_address']);
        }

        if (isset($settings['keep_alive_enabled'])) {
            $this->setKeepAliveEnabled($settings['keep_alive_enabled']);
        }

        if (isset($settings['keep_alive_timeout'])) {
            $this->setKeepAliveTimeout($settings['keep_alive_timeout']);
        }

        if (isset($settings['max_keep_alive_requests_limit'])) {
            $this->setKeepAliveTimeout($settings['max_keep_alive_requests_limit']);
        }
    }

    /**
     * @return int
     */
    public function getListenPort()
    {
        return $this->listenPort;
    }

    /**
     * @param int $port
     * @return Config
     */
    public function setListenPort($port)
    {
        $this->listenPort = $port;

        return $this;
    }

    /**
     * @return string
     */
    public function getListenAddress()
    {
        return $this->listenAddress;
    }

    /**
     * @param string $address
     * @return Config
     */
    public function setListenAddress($address)
    {
        $this->listenAddress = $address;

        return $this;
    }

    /**
     * @return int
     */
    public function getKeepAliveTimeout()
    {
        return $this->keepAliveTimeout;
    }

    /**
     * @param int $timeout
     * @return Config
     */
    public function setKeepAliveTimeout($timeout)
    {
        $this->keepAliveTimeout = $timeout;

        return $this;
    }

    /**
     * @return int
     */
    public function getMaxKeepAliveRequestsLimit()
    {
        return $this->maxKeepAliveRequests;
    }

    /**
     * @param int $limit
     * @return Config
     */
    public function setMaxKeepAliveRequestsLimit($limit)
    {
        $this->maxKeepAliveRequests = $limit;

        return $this;
    }

    /**
     * @return boolean
     */
    public function isKeepAliveEnabled()
    {
        return $this->isKeepAliveEnabled;
    }

    /**
     * @param boolean $isEnabled
     * @return Config
     */
    public function setKeepAliveEnabled($isEnabled)
    {
        $this->isKeepAliveEnabled = $isEnabled;

        return $this;
    }

}