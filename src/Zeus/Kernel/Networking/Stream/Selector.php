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
            throw new \LogicException("Only selectable streams can be registered");
        }

        if (!in_array($operation, [1, 2, 3])) {
            throw new \LogicException("Invalid operation type: " . json_encode($operation));
        }

        $this->streams[] = [$stream, $operation];

        return key($this->streams);
    }

    /**
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
            $resource = $streamDetails[0]->getResource();

            if ($streamDetails[1] & self::OP_READ) {
                $read[] = $resource;
            }
        }

        $streamsChanged = stream_select($read, $write, $except, 0, $timeout);

        return $streamsChanged;
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
            $resource = $streamDetails[0]->getResource();

            if ($streamDetails[1] & self::OP_READ) {
                $read[] = $resource;
            }
        }

        $streamsChanged = stream_select($read, $write, $except, 0, 0);
        $result = [];

        if ($streamsChanged > 0) {
            $resources = array_merge($read, $write);
            foreach ($resources as $resource) {
                $result[] = $this->getStreamForResource($resource);
            }
        }

        return $result;
    }

    protected function getStreamForResource($resource)
    {
        foreach ($this->streams as $streamDetails) {
            if ($streamDetails[0]->getResource() === $resource) {
                return $streamDetails[0];
            }
        }
    }
}