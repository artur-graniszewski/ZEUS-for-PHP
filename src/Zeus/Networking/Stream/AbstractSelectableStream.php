<?php

namespace Zeus\Networking\Stream;

use Zeus\Networking\Exception\SocketException;
use Zeus\Networking\Exception\IOException;
use Zeus\Util\UnitConverter;

use function error_clear_last;
use function error_get_last;
use function stream_select;
use function strlen;

/**
 * Class AbstractSelectableStream
 * @package Zeus\ServerService\Shared\Networking
 * @internal
 */
abstract class AbstractSelectableStream extends AbstractStream implements SelectableStreamInterface
{
    /** @var int */
    protected $soTimeout = 1000;

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
            throw new SocketException(sprintf("Stream is not writable"));
        }
        $size = strlen($this->writeBuffer);
        $sent = 0;

        while ($sent !== $size) {
            $wrote = @$writeMethod($this->resource, $this->writeBuffer);
            if ($wrote < 0 || false === $wrote) {
                $this->isWritable = false;

                throw new SocketException(sprintf("Stream is not writable, sent %d bytes out of %d", max(0, $sent), $size));
            }

            if ($wrote) {
                $sent += $wrote;
                $this->writeBuffer = substr($this->writeBuffer, $wrote);
            }
        };

        $this->dataSent += $sent;
        $this->writeBuffer = '';

        return $sent;
    }

    public function getSoTimeout() : int
    {
        return $this->soTimeout;
    }

    public function setSoTimeout(int $milliseconds)
    {
        $this->soTimeout = $milliseconds;
    }

    /**
     * @param Selector $selector
     * @param int $operation See Selector::OP_READ, Selector::OP_WRITE, Selector::OP_ACCEPT
     * @return SelectionKey
     */
    public function register(Selector $selector, int $operation) : SelectionKey
    {
        return $selector->register($this, $operation);
    }
}