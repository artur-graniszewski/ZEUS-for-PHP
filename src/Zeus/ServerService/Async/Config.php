<?php

namespace Zeus\ServerService\Async;

class Config extends \Zend\Config\Config
{
    /**
     * Config constructor.
     * @param mixed[] $settings
     */
    public function __construct($settings = [])
    {
        parent::__construct($settings, true);
    }

    /**
     * @return int
     */
    public function getListenPort()
    {
        return $this->get('listen_port');
    }

    /**
     * @param int $port
     * @return $this
     */
    public function setListenPort($port)
    {
        $this->offsetSet('listen_port', $port);

        return $this;
    }

    /**
     * @return string
     */
    public function getListenAddress()
    {
        return $this->get('listen_address');
    }

    /**
     * @param string $address
     * @return $this
     */
    public function setListenAddress($address)
    {
        $this->offsetSet('listen_address', $address);

        return $this;
    }

    /**
     * @return int
     */
    public function getKeepAliveTimeout()
    {
        return $this->get('keep_alive_timeout');
    }

    /**
     * @param int $timeout
     * @return $this
     */
    public function setKeepAliveTimeout($timeout)
    {
        $this->offsetSet('keep_alive_timeout', $timeout);

        return $this;
    }
}