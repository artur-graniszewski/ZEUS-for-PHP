<?php

namespace Zeus\Networking\Stream;

use Zeus\Networking\Exception\SocketException;
use Zeus\Util\UnitConverter;

class Selector extends AbstractPhpResource
{
    const OP_READ = 1;
    const OP_WRITE = 2;
    const OP_ALL = 3;

    /** @var mixed[] */
    protected $streams = [];
    protected $streamResources = [self::OP_READ => [], self::OP_WRITE => []];
    protected $streamOps = [];

    /** @var resource */
    protected $selectedStreams = [self::OP_READ => [], self::OP_WRITE => []];

    /**
     * @param SelectableStreamInterface $stream
     * @param int $operation
     * @return int
     */
    public function register(SelectableStreamInterface $stream, int $operation = self::OP_ALL) : int
    {
        if (!in_array($operation, [self::OP_READ, self::OP_WRITE, self::OP_ALL])) {
            throw new \LogicException("Invalid operation type: " . json_encode($operation));
        }

        $resource = $stream->getResource();
        $resourceId = (int) $resource;

        $this->streams[$resourceId] = $stream;
        $this->streamOps[$resourceId] = $operation;

        if ($operation & self::OP_READ) {
            $this->streamResources[self::OP_READ][$resourceId] = $resource;
        }

        if ($operation & self::OP_WRITE) {
            $this->streamResources[self::OP_WRITE][$resourceId] = $resource;
        }

        return $resourceId;
    }

    public function unregister(AbstractSelectableStream $stream)
    {
        $resourceId = array_search($stream, $this->streams);

        if ($resourceId === false) {
            throw new SocketException("No such stream registered: $resourceId");
        }

        unset ($this->streams[$resourceId]);
        unset ($this->streamOps[$resourceId]);
        unset ($this->streamResources[self::OP_READ][$resourceId]);
        unset ($this->streamResources[self::OP_WRITE][$resourceId]);
    }

    /**
     * @param int $timeout Timeout in milliseconds
     * @return int
     */
    public function select($timeout = 0) : int
    {
        $read = $this->streamResources[self::OP_READ];
        $write = $this->streamResources[self::OP_WRITE];
        $except = [];

        $result = [self::OP_READ => [], self::OP_WRITE => []];
        $streamsChanged = @\stream_select($read, $write, $except, 0, UnitConverter::convertMillisecondsToMicroseconds($timeout));

        if ($streamsChanged === 0) {
            return 0;
        }

        if ($read && $write) {
            $uniqueStreams = array_unique(array_merge($read, $write));
        } else {
            $uniqueStreams = $read ? $read : $write;
        }

        $streamsChanged = count($uniqueStreams);

        foreach ($read as $resource) {
            $resourceId = (int) $resource;
            $stream = $this->streams[$resourceId];
            $result[self::OP_READ][] = $stream;
        }

        foreach ($write as $resource) {
            $resourceId = (int) $resource;
            $stream = $this->streams[$resourceId];
            $result[self::OP_WRITE][] = $stream;
        }

        $this->selectedStreams = $result;

        return (int) $streamsChanged;
    }

    /**
     * @param int $operation
     * @return SelectableStreamInterface[]
     */
    public function getSelectedStreams(int $operation = self::OP_ALL) : array
    {
        if (!in_array($operation, [self::OP_READ, self::OP_WRITE, self::OP_ALL])) {
            throw new \LogicException("Invalid operation type: " . json_encode($operation));
        }

        $result = [];
        if ($operation & self::OP_READ) {
            $result += $this->selectedStreams[self::OP_READ];
        }

        if ($operation & self::OP_WRITE) {
            $result += $this->selectedStreams[self::OP_WRITE];
        }

        return $result;
    }
}