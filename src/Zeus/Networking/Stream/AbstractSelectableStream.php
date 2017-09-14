<?php

namespace Zeus\Networking\Stream;

use Zeus\Networking\Exception\SocketException;
use Zeus\Networking\Exception\SocketTimeoutException;
use Zeus\Networking\Exception\StreamException;
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

    /**
     * @param int $timeout Timeout in milliseconds
     * @return bool
     * @throws \Exception
     */
    public function select(int $timeout) : bool
    {
        if (!$this->isReadable()) {
            throw new StreamException("Stream is not readable");
        }

        $write = $except = [];
        $read = [$this->resource];

        try {
            $result = $this->doSelect($read, $write, $except, $timeout);

            return $result === 1;

        } catch (\Exception $exception) {
            $this->isReadable = false;

            throw $exception;
        }
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
        $result = @stream_select($read, $write, $except, 0, UnitConverter::convertMillisecondsToMicroseconds($timeout));
        if ($result !== false) {
            return $result;
        }

        $error = error_get_last();
        if (strstr($error['message'], 'Interrupted system call')) {
            return 0;
        }

        throw new StreamException("Stream select failed: " . $error['message']);
    }

    /**
     * @param callback $writeMethod
     * @return int
     */
    protected function doWrite($writeMethod) : int
    {
        $size = strlen($this->writeBuffer);
        $sent = 0;

        while ($sent !== $size) {
            $wrote = @$writeMethod($this->resource, $this->writeBuffer);

            if ($wrote < 0 || false === $wrote) {
                $this->isWritable = false;

                if ($wrote < strlen($this->writeBuffer)) {
                    throw new SocketException(sprintf("Stream is not writable, sent %d bytes out of %d", max(0, $sent), $size));
                }
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

    /**
     * @return int
     */
    public function getSoTimeout() : int
    {
        return $this->soTimeout;
    }

    /**
     * @param int $milliseconds
     * @return $this
     */
    public function setSoTimeout(int $milliseconds)
    {
        $this->soTimeout = $milliseconds;

        return $this;
    }
}