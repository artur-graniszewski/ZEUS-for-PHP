<?php

namespace Zeus\Networking\Stream;

use Zeus\Networking\Exception\StreamException;
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
    const OP_ACCEPT = 4;
    const OP_ALL = 7;

    /** @var SelectionKey[] */
    private $selectionKeys = [];

    /** @var mixed[] */
    private $streams = [];
    private $streamResources = [self::OP_READ => [], self::OP_WRITE => [], self::OP_ACCEPT => []];

    /** @var mixed[] */
    private $selectedResources = [self::OP_READ => [], self::OP_WRITE => [], self::OP_ACCEPT => []];

    public function register(SelectableStreamInterface $stream, int $operation = self::OP_ALL) : SelectionKey
    {
        if (!in_array($operation, range(self::OP_READ, self::OP_ALL))) {
            throw new \LogicException("Invalid operation type: " . json_encode($operation));
        }

        $resource = $stream->getResource();
        $resourceId = (int) $resource;

        if (isset($this->selectionKeys[$resourceId])) {
            $selectionKey = $this->selectionKeys[$resourceId];
        } else {
            $selectionKey = new SelectionKey($stream, $this);
        }

        $this->selectionKeys[$resourceId] = $selectionKey;
        $this->streams[$resourceId] = $stream;

        // @todo: forbid to register already registered operation?
        if ($operation & self::OP_READ) {
            $this->streamResources[self::OP_READ][$resourceId] = $resource;
        }

        if ($operation & self::OP_WRITE) {
            $this->streamResources[self::OP_WRITE][$resourceId] = $resource;
        }

        if ($operation & self::OP_ACCEPT) {
            $this->streamResources[self::OP_ACCEPT][$resourceId] = $resource;
        }

        return $selectionKey;
    }

    public function unregister(SelectableStreamInterface $stream, int $operation = self::OP_ALL)
    {
        $resourceId = array_search($stream, $this->streams);

        if ($resourceId === false) {
            throw new StreamException("No such stream registered: $resourceId");
        }

        if ($operation & self::OP_READ) {
            unset ($this->streamResources[self::OP_READ][$resourceId]);
            unset ($this->selectedResources[self::OP_READ][$resourceId]);
        }

        if ($operation & self::OP_WRITE) {
            unset ($this->streamResources[self::OP_WRITE][$resourceId]);
            unset ($this->selectedResources[self::OP_WRITE][$resourceId]);
        }

        if ($operation & self::OP_ACCEPT) {
            unset ($this->streamResources[self::OP_ACCEPT][$resourceId]);
            unset ($this->selectedResources[self::OP_ACCEPT][$resourceId]);
        }

        if (($operation === self::OP_ALL)
            ||
            (
                !isset($this->streamResources[self::OP_READ][$resourceId])
                &&
                !isset($this->streamResources[self::OP_WRITE][$resourceId])
                &&
                !isset($this->selectedResources[self::OP_ACCEPT][$resourceId])
            )
        ) {
            //$this->selectionKeys[$resourceId]->cancel();
            unset ($this->streamResources[self::OP_READ][$resourceId]);
            unset ($this->selectedResources[self::OP_READ][$resourceId]);
            unset ($this->streamResources[self::OP_WRITE][$resourceId]);
            unset ($this->selectedResources[self::OP_WRITE][$resourceId]);
            unset ($this->streamResources[self::OP_ACCEPT][$resourceId]);
            unset ($this->selectedResources[self::OP_ACCEPT][$resourceId]);

            unset ($this->selectionKeys[$resourceId]);
            unset ($this->streams[$resourceId]);
        }
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
                unset ($this->streamResources[self::OP_ACCEPT][$key]);
                unset ($this->selectionKeys[$key]);
            }
        }

        $read = $this->streamResources[self::OP_READ] + $this->streamResources[self::OP_ACCEPT];
        $write = $this->streamResources[self::OP_WRITE];

        if (!$read && !$write) {
            return 0;
        }
        $except = [];

        $streamsChanged = @stream_select($read, $write, $except, 0, UnitConverter::convertMillisecondsToMicroseconds($timeout));

        if ($streamsChanged === false) {
            $error = error_get_last();
            throw new StreamException("Select failed: " . $error['message']);
        }
        if ($streamsChanged === 0) {
            return 0;
        }

        if ($read && $write) {
            $uniqueStreams = [];

            foreach ($read as $resource) {
                $uniqueStreams[(int) $resource] = 1;
            }

            foreach ($write as $resource) {
                $uniqueStreams[(int) $resource] = 1;
            }
        } else {
            $uniqueStreams = $read ? $read : $write;
        }

        $this->selectedResources = [self::OP_READ => $read, self::OP_WRITE => $write];

        $streamsChanged = count($uniqueStreams);

        return $streamsChanged;
    }

    /**
     * @return SelectionKey[]
     */
    public function getSelectionKeys() : array
    {
        $result = [];

        foreach ($this->selectionKeys as $selectionKey) {
            $selectionKey->setAcceptable(false);
            $selectionKey->setReadable(false);
            $selectionKey->setWritable(false);
        }

        foreach ($this->selectedResources as $type => $pool) {
            foreach ($pool as $resource) {
                $resourceId = (int)$resource;
                $selectionKey = $this->selectionKeys[$resourceId];

                if (isset($this->streamResources[self::OP_ACCEPT][$resourceId])) {
                    $selectionKey->setAcceptable(true);
                    $result[$resourceId] = $selectionKey;
                    continue;
                }

                if ($type & self::OP_WRITE) {
                    $selectionKey->setWritable(true);
                }

                if ($type & self::OP_READ) {
                    $selectionKey->setReadable(true);
                }

                $result[$resourceId] = $selectionKey;
            }
        }

        return array_values($result);
    }

    /**
     * @param int $operation
     * @return SelectableStreamInterface[]
     */
    public function getSelectedStreams(int $operation = self::OP_ALL) : array
    {
        if (!in_array($operation, range(self::OP_READ, self::OP_ALL))) {
            throw new \LogicException("Invalid operation type: " . json_encode($operation));
        }

        $streams = [];

        foreach ($this->selectedResources as $type => $pool) {
            foreach ($pool as $resource) {
                $resourceId = (int)$resource;
                if (!isset($this->streams[$resourceId])) {
                    // stream was unregistered before executing getSelectedStreams()
                    continue;
                }
                $stream = $this->streams[$resourceId];

                if ($operation & self::OP_READ) {
                    if (isset($this->streamResources[self::OP_READ][$resourceId]) && !isset($streams[$resourceId])) {
                        $streams[$resourceId] = $stream;
                    }
                }

                if ($operation & self::OP_WRITE) {
                    if (isset($this->streamResources[self::OP_WRITE][$resourceId]) && !isset($streams[$resourceId])) {
                        $streams[$resourceId] = $stream;
                    }
                }

                if ($operation & self::OP_ACCEPT) {
                    if (isset($this->streamResources[self::OP_ACCEPT][$resourceId]) && !isset($streams[$resourceId])) {
                        $streams[$resourceId] = $stream;
                    }
                }
            }
        }

        return array_values($streams);
    }
}