<?php

namespace Zeus\Networking\Stream;
use Zeus\Networking\Exception\SocketException;
use Zeus\Networking\Exception\SocketTimeoutException;
use Zeus\Util\UnitConverter;

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
     */
    public function select(int $timeout) : bool
    {
        if (!$this->isReadable()) {
            throw new \LogicException("Stream is not readable");
        }

        $write = $except = [];
        $read = [$this->resource];

        try {
            $result = $this->doSelect($read, $write, $except, $timeout);

            return $result === 1;

        } catch (\exception $exception) {
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
        @\error_clear_last();
        $result = @\stream_select($read, $write, $except, 0, UnitConverter::convertMillisecondsToMicroseconds($timeout));
        if ($result !== false) {
            return $result;
        }

        $error = \error_get_last();
        if (strstr($error['message'], 'Interrupted system call')) {
            return 0;
        }

        throw new \RuntimeException("Stream select failed: " . $error['message']);
    }

    /**
     * @param callback $writeMethod
     * @return $this
     */
    protected function doWrite($writeMethod)
    {
        $size = strlen($this->writeBuffer);
        $sent = 0;

        $read = $except = [];
        $write = [$this->resource];

        $timeout = $this->getSoTimeout();

        while ($sent !== $size) {
            $amount = 1;
            $toWrite = strlen($this->writeBuffer);
            $wrote = @$writeMethod($this->resource, $this->writeBuffer);

            // write failed, try to wait a bit
            if ($timeout > 0 && $wrote >= 0 && $wrote < strlen($this->writeBuffer)) {
                $amount = $this->doSelect($read, $write, $except, $timeout);

                if ($amount === 0) {
                    $this->writeBuffer = substr($this->writeBuffer, $wrote);
                    throw new SocketTimeoutException(sprintf("Write timeout exceeded, sent %d bytes", $wrote));
                }
            }

            if ($wrote < 0 || false === $wrote || $amount === false) {
                //if ( || $this->isEof())
                $this->isWritable = false;

                if ($wrote < strlen($this->writeBuffer)) {
                    throw new SocketException(sprintf("Stream is not writable, sent %d bytes out of %d", max(0, $wrote), $toWrite));
                }
            }

            if ($wrote) {
                $sent += $wrote;
                $this->writeBuffer = substr($this->writeBuffer, $wrote);
            }
        };

        $this->dataSent += $sent;
        $this->writeBuffer = '';

        return $this;
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