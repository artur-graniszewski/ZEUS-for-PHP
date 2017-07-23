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

    /** @var resource */
    protected $selectedStreams = [self::OP_READ => [], self::OP_WRITE => []];

    /**
     * @param AbstractSelectableStream $stream
     * @param int $operation
     * @return int
     */
    public function register(AbstractSelectableStream $stream, int $operation = self::OP_ALL) : int
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

    public function unregister(AbstractSelectableStream $stream)
    {
        foreach ($this->streams as $key => $value) {
            if ($stream === $value[0]) {
                unset ($this->streams[$key]);
//                $this->selectedStreams[self::OP_READ] = [];
//                $this->selectedStreams[self::OP_WRITE] = [];

                return $this;
            }
        }

        throw new SocketException("No such stream registered");
    }

    /**
     * @param int $timeout Timeout in milliseconds
     * @return int
     */
    public function select($timeout = 0)
    {
        //$this->streams = $this->getActiveStreams();

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

        $result = [self::OP_READ => [], self::OP_WRITE => []];
        $streamsChanged = @stream_select($read, $write, $except, 0, UnitConverter::convertMillisecondsToMicroseconds($timeout));

        if ($streamsChanged === 0) {
            return 0;
        }

        $streamsChanged = count(array_unique(array_merge($read, $write)));

        foreach ($read as $resource) {
            $stream = $this->getStreamForResource($resource);
            $result[self::OP_READ][] = $stream;
        }

        foreach ($write as $resource) {
            $stream = $this->getStreamForResource($resource);
            $result[self::OP_WRITE][] = $stream;
        }


        $this->selectedStreams = $result;

        return (int) $streamsChanged;
    }

    /**
     * @param int $operation
     * @return array|AbstractStream[]
     */
    public function getSelectedStreams(int $operation = self::OP_ALL) : array
    {
        if (!in_array($operation, [self::OP_READ, self::OP_WRITE, self::OP_ALL])) {
            throw new \LogicException("Invalid operation type: " . json_encode($operation));
        }

        //$this->streams = $this->getActiveStreams();

        $result = [];
        if ($operation & self::OP_READ) {
            foreach ($this->selectedStreams[self::OP_READ] as $stream) {
                $result[] = $stream;
            }
        }

        if ($operation & self::OP_WRITE) {
            foreach ($this->selectedStreams[self::OP_WRITE] as $stream) {
                if (!in_array($stream, $result, true)) {
                    $result[] = $stream;
                }
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