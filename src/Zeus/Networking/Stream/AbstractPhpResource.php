<?php

namespace Zeus\Networking\Stream;

abstract class AbstractPhpResource implements ResourceInterface
{
    /** @var resource */
    protected $resource;

    /** @var int */
    protected $streamId;

    /**
     * SocketConnection constructor.
     * @param resource $resource
     * @param string $peerName
     */
    public function __construct($resource, string $peerName = null)
    {
        $this->setResource($resource);
    }

    /**
     * @return resource
     */
    public function getResource()
    {
        return $this->resource;
    }

    /**
     * @return int
     */
    public function getResourceId() : int
    {
        return $this->streamId;
    }

    /**
     * @param resource $resource
     * @return $this
     */
    protected function setResource($resource)
    {
        if (!is_resource($resource)) {
            throw new \LogicException("Unsupported resource type");
        }
        $this->streamId = (int) $resource;
        $this->resource = $resource;

        return $this;
    }
}