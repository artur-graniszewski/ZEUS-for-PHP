<?php

namespace Zeus\ServerService\Http;

class Config extends \Zend\Config\Config implements HttpConfigInterface
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

    /**
     * @return int
     */
    public function getMaxKeepAliveRequestsLimit()
    {
        return $this->get('max_keep_alive_requests_limit');
    }

    /**
     * @param int $limit
     * @return $this
     */
    public function setMaxKeepAliveRequestsLimit($limit)
    {
        $this->offsetSet('max_keep_alive_requests_limit', $limit);

        return $this;
    }

    /**
     * @return bool
     */
    public function isKeepAliveEnabled()
    {
        return $this->get('enable_keep_alive');
    }

    /**
     * @param bool $isEnabled
     * @return $this
     */
    public function setIsKeepAliveEnabled($isEnabled)
    {
        $this->offsetSet('enable_keep_alive', $isEnabled);

        return $this;
    }

}