<?php

namespace Zeus\IO\Stream;

use Zeus\IO\Exception\IOException;

use function stream_socket_get_name;
use function strlen;

/**
 * Class AbstractSelectableStream
 * @package Zeus\ServerService\Shared\Networking
 * @internal
 */
abstract class AbstractSelectableStream extends AbstractStream implements SelectableStreamInterface
{
    /** @var string */
    private $localAddress;

    /**
     * @return string Server address (IP) or null if unknown
     */
    public function getLocalAddress() : string
    {
        return $this->localAddress ? $this->localAddress : $this->localAddress = @stream_socket_get_name($this->resource, false);
    }

    /**
     * @param callback $writeMethod
     * @return int
     */
    protected function doWrite($writeMethod) : int
    {
        if ($this->isEof()) {
            $this->isWritable = false;
            throw new IOException(sprintf("Stream is not writable"));
        }
        $size = strlen($this->writeBuffer);
        $sent = 0;

        $wrote = @$writeMethod($this->resource, $this->writeBuffer);
        if ($wrote < 0 || false === $wrote) {
            $this->isWritable = false;

            throw new IOException(sprintf("Stream is not writable, sent %d bytes out of %d", max(0, $sent), $size));
        }

        if ($wrote) {
            $sent += $wrote;
            $this->writeBuffer = substr($this->writeBuffer, $wrote);
        }

        $this->dataSent += $sent;
        $this->writeBuffer = '';

        return $sent;
    }

    /**
     * @param Selector $selector
     * @param int $operation See SelectionKey::OP_READ, SelectionKey::OP_WRITE, SelectionKey::OP_ACCEPT
     * @return SelectionKey
     */
    public function register(Selector $selector, int $operation) : SelectionKey
    {
        return $selector->register($this, $operation);
    }
}