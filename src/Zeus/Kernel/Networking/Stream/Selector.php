<?php

namespace Zeus\Kernel\Networking\Stream;

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
    public function register(AbstractStream $stream, $operation = self::OP_ALL)
    {
        if (!$stream instanceof SelectableStreamInterface) {
<<<<<<< HEAD
            $interface = SelectableStreamInterface::class;
            throw new \LogicException("Stream class must implement $interface");
        }

        if (!in_array($operation, [self::OP_READ, self::OP_WRITE, self::OP_ALL])) {
=======
            throw new \LogicException("Only selectable streams can be registered");
        }

        if (!in_array($operation, [1, 2, 3])) {
>>>>>>> 2371fdb1db521ecdd3c747d939f597de711fcb0e
            throw new \LogicException("Invalid operation type: " . json_encode($operation));
        }

        $this->streams[] = [$stream, $operation];

        return key($this->streams);
    }

    /**
<<<<<<< HEAD
=======
     * @return AbstractStream[]
     */
    protected function getActiveStreams()
    {
        $streams = [];

        foreach ($this->streams as $streamDetails) {
            if ($streamDetails[0]->isClosed()) {
                continue;
            }

            $streams[] = $streamDetails;
        }

        return $streams;
    }

    /**
>>>>>>> 2371fdb1db521ecdd3c747d939f597de711fcb0e
     * @param int $timeout
     * @return int
     */
    public function select($timeout = 0)
    {
        $this->streams = $this->getActiveStreams();

        $read = [];
        $write = [];
        $except = [];

        foreach ($this->streams as $streamDetails) {
<<<<<<< HEAD
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

        $streamsChanged = @stream_select($read, $write, $except, 0, $timeout);

        if ($streamsChanged > 0) {
            $streamsChanged = count(array_unique(array_merge($read, $write)));
        }

        return (int) $streamsChanged;
=======
            $resource = $streamDetails[0]->getResource();

            if ($streamDetails[1] & self::OP_READ) {
                $read[] = $resource;
            }
        }

        $streamsChanged = stream_select($read, $write, $except, 0, $timeout);

        return $streamsChanged;
>>>>>>> 2371fdb1db521ecdd3c747d939f597de711fcb0e
    }

    /**
     * @return AbstractStream[]
     */
    public function getSelectedKeys()
    {
        $this->streams = $this->getActiveStreams();

        $read = [];
        $write = [];
        $except = [];

        foreach ($this->streams as $streamDetails) {
<<<<<<< HEAD
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
=======
            $resource = $streamDetails[0]->getResource();

            if ($streamDetails[1] & self::OP_READ) {
                $read[] = $resource;
            }
        }

        $streamsChanged = stream_select($read, $write, $except, 0, 0);
        $result = [];

        if ($streamsChanged > 0) {
            $resources = array_merge($read, $write);
>>>>>>> 2371fdb1db521ecdd3c747d939f597de711fcb0e
            foreach ($resources as $resource) {
                $result[] = $this->getStreamForResource($resource);
            }
        }

        return $result;
    }

<<<<<<< HEAD
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
    private function getActiveStreams()
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
=======
    protected function getStreamForResource($resource)
    {
        foreach ($this->streams as $streamDetails) {
            if ($streamDetails[0]->getResource() === $resource) {
                return $streamDetails[0];
            }
        }
>>>>>>> 2371fdb1db521ecdd3c747d939f597de711fcb0e
    }
}