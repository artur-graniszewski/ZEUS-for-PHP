<?php

namespace Zeus\Networking\Stream;

use Zeus\Networking\Exception\SocketException;
use Zeus\Util\UnitConverter;
use function stream_select;
use function in_array;
use function array_search;
use function array_unique;
use function array_merge;
use function count;

class Selector
{
    const OP_READ = 1;
    const OP_WRITE = 2;
    const OP_ALL = 3;

    /** @var mixed[] */
    protected $streams = [];
    protected $streamResources = [self::OP_READ => [], self::OP_WRITE => []];

    /** @var mixed[] */
    protected $selectedStreams = [];

    /** @var mixed[] */
    protected $selectedResources = [self::OP_READ => [], self::OP_WRITE => []];

    /**
     * @param SelectableStreamInterface $stream
     * @param int $operation
     * @return void
     */
    public function register(SelectableStreamInterface $stream, int $operation = self::OP_ALL)
    {
        if (!in_array($operation, [self::OP_READ, self::OP_WRITE, self::OP_ALL])) {
            throw new \LogicException("Invalid operation type: " . json_encode($operation));
        }

        $resource = $stream->getResource();
        $resourceId = (int) $resource;

        $this->streams[$resourceId] = $stream;

        if ($operation & self::OP_READ) {
            $this->streamResources[self::OP_READ][$resourceId] = $resource;
        }

        if ($operation & self::OP_WRITE) {
            $this->streamResources[self::OP_WRITE][$resourceId] = $resource;
        }
    }

    public function unregister(AbstractSelectableStream $stream)
    {
        $resourceId = array_search($stream, $this->streams);

        if ($resourceId === false) {
            throw new SocketException("No such stream registered: $resourceId");
        }

        unset ($this->streams[$resourceId]);
        unset ($this->streamResources[self::OP_READ][$resourceId]);
        unset ($this->streamResources[self::OP_WRITE][$resourceId]);
    }

    /**
     * @param int $timeout Timeout in milliseconds
     * @return int
     */
    public function select(int $timeout = 0) : int
    {
        foreach($this->streams as $key => $stream) {
            if ($stream->isClosed()) {
                unset ($this->streamResources[self::OP_READ][$key]);
                unset ($this->streamResources[self::OP_WRITE][$key]);
            }
        }

        $read = $this->streamResources[self::OP_READ];
        $write = $this->streamResources[self::OP_WRITE];
        $except = [];

        $streamsChanged = @stream_select($read, $write, $except, 0, UnitConverter::convertMillisecondsToMicroseconds($timeout));

        if ($streamsChanged === 0) {
            return 0;
        }

        if ($read && $write) {
            $uniqueStreams = array_unique(array_merge($read, $write));
        } else {
            $uniqueStreams = $read ? $read : $write;
        }

        $this->selectedResources = $uniqueStreams;
        $this->selectedStreams = [self::OP_READ => [], self::OP_WRITE => []];

        $streamsChanged = count($uniqueStreams);

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

        $result = [self::OP_READ => [], self::OP_WRITE => []];

        foreach ($this->selectedResources as $resource) {
            $resourceId = (int) $resource;
            if (!isset($this->streams[$resourceId])) {
                // stream was unregistered before executing getSelectedStreams()
                continue;
            }
            $stream = $this->streams[$resourceId];

            if (isset($this->streamResources[self::OP_READ][$resourceId])) {
                $result[self::OP_READ][] = $stream;
            }

            if (isset($this->streamResources[self::OP_WRITE][$resourceId])) {
                $result[self::OP_WRITE][] = $stream;
            }
        }

        $this->selectedStreams = $result;

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