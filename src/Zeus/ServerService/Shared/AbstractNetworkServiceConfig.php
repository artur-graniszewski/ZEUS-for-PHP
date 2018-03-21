<?php

namespace Zeus\ServerService\Shared;

use Zend\Config\Config;

class AbstractNetworkServiceConfig extends Config
{
    /**
     * Config constructor.
     * @param mixed[] $settings
     */
    public function __construct(array $settings = [])
    {
        parent::__construct($settings, true);
    }

    public function getListenPort() : int
    {
        return $this->get('listen_port');
    }

    public function setListenPort(int $port)
    {
        $this->offsetSet('listen_port', $port);
    }

    public function getListenAddress() : string
    {
        return $this->get('listen_address');
    }

    public function setListenAddress(string $address)
    {
        $this->offsetSet('listen_address', $address);
    }

    public function getKeepAliveTimeout() : int
    {
        return $this->get('keep_alive_timeout', 0);
    }

    public function setKeepAliveTimeout(int $timeout)
    {
        $this->offsetSet('keep_alive_timeout', $timeout);
    }
}