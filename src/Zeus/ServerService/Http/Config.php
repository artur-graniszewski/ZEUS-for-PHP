<?php

namespace Zeus\ServerService\Http;

use Zeus\ServerService\Shared\AbstractNetworkServiceConfig;

class Config extends AbstractNetworkServiceConfig implements HttpConfigInterface
{
    public function getMaxKeepAliveRequestsLimit() : int
    {
        return $this->get('max_keep_alive_requests_limit');
    }

    public function setMaxKeepAliveRequestsLimit(int $limit)
    {
        $this->offsetSet('max_keep_alive_requests_limit', $limit);
    }

    public function isKeepAliveEnabled() : bool
    {
        return $this->get('enable_keep_alive');
    }

    public function setIsKeepAliveEnabled(bool $isEnabled)
    {
        $this->offsetSet('enable_keep_alive', $isEnabled);
    }

}