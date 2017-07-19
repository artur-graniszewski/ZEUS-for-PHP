<?php

namespace Zeus\Networking\Stream;

abstract class AbstractPhpResource
{
    /** @var resource */
    protected $resource;

    /**
     * @return resource
     */
    public function getResource()
    {
        return $this->resource;
    }

    /**
     * @param resource $resource
     * @return $this
     */
    protected function setResource($resource)
    {
        $this->resource = $resource;

        return $this;
    }
}