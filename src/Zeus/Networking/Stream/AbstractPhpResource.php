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
        if (function_exists('stream_set_read_buffer')) {
            //\stream_set_read_buffer($resource, 0);
        }
        if (function_exists('stream_set_write_buffer')) {
            //\stream_set_write_buffer($resource, 0);
        }

        $this->resource = $resource;

        return $this;
    }
}