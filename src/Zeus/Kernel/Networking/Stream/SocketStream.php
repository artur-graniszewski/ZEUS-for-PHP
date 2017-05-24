<?php

namespace Zeus\Kernel\Networking\Stream;

/**
 * Class SocketStream
 * @package Zeus\ServerService\Shared\Networking
 * @internal
 */
final class SocketStream extends AbstractSelectableStream implements NetworkStreamInterface
{
    protected $peerName;

    /**
     * SocketConnection constructor.
     * @param resource $stream
     * @param string $peerName
     */
    public function __construct($stream, $peerName = null)
    {
        $this->peerName = $peerName;

        parent::__construct($stream);

        $this->writeCallback = defined("HHVM_VERSION") ? 'fwrite' : 'stream_socket_sendto';
    }

    /**
     * @return $this
     */
    protected function doClose()
    {
        if (!$this->isEof()) {
            stream_socket_shutdown($this->stream, STREAM_SHUT_RDWR);
        }

        parent::doClose();

        return $this;
    }

    /**
     * @return string|null Server address (IP) or null if unknown
     */
    public function getServerAddress()
    {
        return @stream_socket_get_name($this->stream, false);
    }

    /**
     * @return string|null Remote address (client IP) or null if unknown
     */
    public function getRemoteAddress()
    {
        return $this->peerName ? $this->peerName : @stream_socket_get_name($this->stream, true);
    }

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
     * @param callback $writeMethod
     * @return $this
     */
    protected function doWrite($writeMethod)
    {
        if (!$this->isWritable()) {
            $this->data = '';

            return $this;
        }

        $size = strlen($this->data);
        $sent = 0;

        $read = $except = [];
        $write = [$this->stream];
        while ($sent !== $size) {
            $amount = 1;
            $wrote = $writeMethod($this->stream, $this->data);

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
                $this->data = substr($this->data, $wrote);
            }
        };

        $this->dataSent += $sent;
        $this->data = '';

        return $this;
    }
}