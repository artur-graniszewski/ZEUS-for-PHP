<?php

namespace Zeus\Networking\Stream;

use Zeus\Util\UnitConverter;

class Selector extends AbstractPhpResource
{
    const OP_READ = 1;
    const OP_WRITE = 2;
    const OP_ALL = 3;

    /** @var mixed[] */
    protected $streams = [];

    /**
     * @param AbstractStream $stream
     * @param int $operation
     * @return int
     */
    public function register(AbstractStream $stream, int $operation = self::OP_ALL) : int
    {
        if (!$stream instanceof SelectableStreamInterface) {
            $interface = SelectableStreamInterface::class;
            throw new \LogicException("Stream class must implement $interface");
        }

        if (!in_array($operation, [self::OP_READ, self::OP_WRITE, self::OP_ALL])) {
            throw new \LogicException("Invalid operation type: " . json_encode($operation));
        }

        $this->streams[] = [$stream, $operation];

        return key($this->streams);
    }

    /**
     * @param int $timeout Timeout in milliseconds
     * @return int
     */
    public function select($timeout = 0)
    {
        $this->streams = $this->getActiveStreams();

        $read = [];
        $write = [];
        $except = [];

        foreach ($this->streams as $streamDetails) {
            /** @var AbstractStream $stream */
            list($stream, $operation) = $streamDetails;
            $resource = $stream->getResource();

            if ($operation & self::OP_READ) {
                $read[] = $resource;
            }

            if ($operation & self::OP_WRITE) {
                $write[] = $resource;
            }
        }

        $streamsChanged = @stream_select($read, $write, $except, 0, UnitConverter::convertMillisecondsToMicroseconds($timeout));

        if ($streamsChanged > 0) {
            $streamsChanged = count(array_unique(array_merge($read, $write)));
        }

        return (int) $streamsChanged;
    }

    /**
     * @return AbstractStream[]
     */
    public function getSelectedStreams() : array
    {
        $this->streams = $this->getActiveStreams();

        $read = [];
        $write = [];
        $except = [];

        foreach ($this->streams as $streamDetails) {
            /** @var AbstractStream $stream */
            list($stream, $operation) = $streamDetails;
            $resource = $stream->getResource();

            if ($operation & self::OP_READ) {
                $read[] = $resource;
            }

            if ($operation & self::OP_WRITE) {
                $write[] = $resource;
            }
        }

        $streamsChanged = @stream_select($read, $write, $except, 0, 0);
        $result = [];

        if ($streamsChanged > 0) {
            $resources = array_unique(array_merge($read, $write));
            foreach ($resources as $resource) {
                $result[] = $this->getStreamForResource($resource);
            }
        }

        return $result;
    }

    /**
     * @param resource $resource
     * @return AbstractStream
     */
    private function getStreamForResource($resource)
    {
        foreach ($this->streams as $streamDetails) {
            /** @var AbstractStream $stream */
            list($stream) = $streamDetails;
            if ($stream->getResource() === $resource) {
                return $streamDetails[0];
            }
        }

        throw new \LogicException("No stream found for the resource $resource");
    }

    /**
     * @return AbstractStream[]
     */
    private function getActiveStreams() : array
    {
        $streams = [];

        foreach ($this->streams as $streamDetails) {
            /** @var AbstractStream $stream */
            list($stream) = $streamDetails;
            if ($stream->isClosed()) {
                continue;
            }

            $streams[] = $streamDetails;
        }

        return $streams;
    }
}