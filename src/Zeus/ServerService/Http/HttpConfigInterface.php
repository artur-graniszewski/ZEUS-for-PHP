<?php

namespace Zeus\ServerService\Http;

interface HttpConfigInterface
{
    public function getKeepAliveTimeout() : int;

    public function setKeepAliveTimeout(int $timeout);

    public function getMaxKeepAliveRequestsLimit() : int;

    public function setMaxKeepAliveRequestsLimit(int $limit);

    public function isKeepAliveEnabled() : bool;

    public function setIsKeepAliveEnabled(bool $isEnabled);
}