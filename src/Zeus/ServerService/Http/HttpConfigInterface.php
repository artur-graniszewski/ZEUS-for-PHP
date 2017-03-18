<?php

namespace Zeus\ServerService\Http;

interface HttpConfigInterface
{
    /**
     * @return int
     */
    public function getKeepAliveTimeout();

    /**
     * @param int $timeout
     * @return HttpConfigInterface
     */
    public function setKeepAliveTimeout($timeout);

    /**
     * @return int
     */
    public function getMaxKeepAliveRequestsLimit();

    /**
     * @param int $limit
     * @return HttpConfigInterface
     */
    public function setMaxKeepAliveRequestsLimit($limit);

    /**
     * @return boolean
     */
    public function isKeepAliveEnabled();

    /**
     * @param boolean $isEnabled
     * @return HttpConfigInterface
     */
    public function setIsKeepAliveEnabled($isEnabled);

}