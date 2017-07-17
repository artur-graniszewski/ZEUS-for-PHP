<?php

namespace Zeus\Networking\Stream;
use Zeus\Util\UnitConverter;

/**
 * Class AbstractSelectableStream
 * @package Zeus\ServerService\Shared\Networking
 * @internal
 */
abstract class AbstractSelectableStream extends AbstractStream implements SelectableStreamInterface
{
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

        @trigger_error("");
        $result = $this->doSelect($read, $write, $except, $timeout);
        if ($result !== false) {
            return $result === 1;
        }

        $this->isReadable = false;
        $error = error_get_last();
        throw new \RuntimeException("Stream select failed: " . $error['message']);
    }

    /**
     * @param resource[] $read
     * @param resource[] $write
     * @param resource[] $except
     * @param int $timeout
     * @return bool|int
     */
    protected function doSelect(& $read, & $write, & $except, $timeout)
    {
        @trigger_error("");
        $result = @stream_select($read, $write, $except, 0, UnitConverter::convertMillisecondsToMicroseconds($timeout));
        if ($result !== false) {
            return $result;
        }

        $error = error_get_last();
        if ($result === false && strstr($error['message'], 'Interrupted system call')) {
            return 0;
        }

        return false;
    }

    /**
     * @param bool|string $ending
     * @return bool|string
     */
    public function read(string $ending = '')
    {
        if (!$this->isReadable()) {
            throw new \LogicException("Stream is not readable");
        }

        if (!$this->select(0)) {
            return false;
        }

        return parent::read($ending);
    }

    /**
     * @param callback $writeMethod
     * @return $this
     */
    protected function doWrite($writeMethod)
    {
        if (!$this->isWritable()) {
            $this->writeBuffer = '';

            return $this;
        }

        $size = strlen($this->writeBuffer);
        $sent = 0;

        $read = $except = [];
        $write = [$this->resource];
        while ($sent !== $size) {
            $amount = 1;
            $wrote = $writeMethod($this->resource, $this->writeBuffer);

            // write failed, try to wait a bit
            if ($wrote === 0) {
                do {
                    $amount = $this->doSelect($read, $write, $except, 1000);
                } while($amount === 0);
            }

            if ($wrote < 0 || false === $wrote || $amount === false || $this->isEof()) {
                $this->isWritable = false;
                $this->isReadable = false;// remove this?
                $this->close();
                break;
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
}