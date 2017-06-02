<?php

namespace Zeus\Kernel\Networking\Stream;

/**
 * Class AbstractSelectableStream
 * @package Zeus\ServerService\Shared\Networking
 * @internal
 */
abstract class AbstractSelectableStream extends AbstractStream implements SelectableStreamInterface
{
    protected $peerName;

    /**
     * @param int $timeout
     * @return bool
     */
    public function select($timeout)
    {
        if (!$this->isReadable()) {
            throw new \LogicException("Stream is not readable");
        }

        $write = $except = [];
        $read = [$this->stream];

        @trigger_error("");
        $result = $this->doSelect($read, $write, $except, $timeout);
        if ($result !== false) {
            return $result === 1;
        }

        $this->isReadable = false;
        $error = error_get_last();
        throw new \RuntimeException("Stream select failed: " . $error['message']);
    }

    protected function doSelect(& $read, & $write, & $except, $timeout)
    {
        @trigger_error("");
        $result = @stream_select($read, $write, $except, $timeout);
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
     * @param null|string $ending
     * @return bool|string
     */
    public function read($ending = false)
    {
        if (!$this->isReadable()) {
            throw new \LogicException("Stream is not readable");
        }

        if (!$this->select(1)) {
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
        $write = [$this->stream];
        while ($sent !== $size) {
            $amount = 1;
            $wrote = $writeMethod($this->stream, $this->writeBuffer);

            // write failed, try to wait a bit
            if ($wrote === 0) {
                do {
                    $amount = $this->doSelect($read, $write, $except, 1);
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