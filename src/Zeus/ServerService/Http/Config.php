<?php

namespace Zeus\ServerService\Http;

use Zeus\ServerService\Shared\AbstractNetworkServiceConfig;

class Config extends AbstractNetworkServiceConfig implements HttpConfigInterface
{
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