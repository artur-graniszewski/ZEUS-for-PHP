<?php

namespace Zeus\IO\Stream;

use Zeus\IO\Exception\IOException;
use Zeus\Util\UnitConverter;

use function error_clear_last;
use function error_get_last;
use function stream_select;
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
     * @param int $timeout Timeout in milliseconds
     * @return bool
     * @throws \Exception
     */
    public function select(int $timeout) : bool
    {
        if (!$this->isReadable()) {
            throw new IOException("Stream is not readable");
        }

        $write = $except = [];
        $read = [$this->resource];

        try {
            $result = $this->doSelect($read, $write, $except, $timeout);

            return $result === 1;

        } catch (IOException $exception) {
            $this->isReadable = false;

            throw $exception;
        }
    }

    /**
     * @return string Server address (IP) or null if unknown
     */
    public function getLocalAddress() : string
    {
        return $this->localAddress ? $this->localAddress : $this->localAddress = @stream_socket_get_name($this->resource, false);
    }

    /**
     * @param resource[] $read
     * @param resource[] $write
     * @param resource[] $except
     * @param int $timeout
     * @return int
     */
    protected function doSelect(& $read, & $write, & $except, $timeout) : int
    {
        error_clear_last();
        $result = @stream_select($read, $write, $except, 0, $timeout > 0 ? UnitConverter::convertMillisecondsToMicroseconds($timeout) : 0);
        if ($result !== false) {
            return $result;
        }

        $error = error_get_last();
        if (strstr($error['message'], 'Interrupted system call')) {
            return 0;
        }

        throw new IOException("Stream select failed: " . $error['message']);
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