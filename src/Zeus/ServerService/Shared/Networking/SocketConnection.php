<?php

namespace Zeus\ServerService\Shared\Networking;

/**
 * Class SocketConnection
 * @package Zeus\ServerService\Shared\Networking
 * @internal
 */
final class SocketConnection implements ConnectionInterface, FlushableConnectionInterface
{
    const DEFAULT_WRITE_BUFFER_SIZE = 65536;
    const DEFAULT_READ_BUFFER_SIZE = 65536;

    protected $isWritable = true;

    protected $isReadable = true;

    protected $isClosing = false;

    protected $stream;

    protected $data = '';

    protected $writeBufferSize = self::DEFAULT_WRITE_BUFFER_SIZE;

    protected $readBufferSize = self::DEFAULT_READ_BUFFER_SIZE;

    protected $dataSent = 0;

    protected $dataReceived = 0;

    protected $writeCallback = '';

    /**
     * SocketConnection constructor.
     * @param resource $stream
     */
    public function __construct($stream)
    {
        $this->stream = $stream;

        stream_set_blocking($this->stream, false);

        if (function_exists('stream_set_chunk_size')) {
            stream_set_chunk_size($this->stream, 1);
        }

        if (function_exists('stream_set_read_buffer')) {
            stream_set_read_buffer($this->stream, 0);
        }

        if (function_exists('stream_set_write_buffer')) {
            stream_set_write_buffer($this->stream, 0);
        }

        $this->writeCallback = defined("HHVM_VERSION") ? 'fwrite' : 'stream_socket_sendto';
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
        return @stream_socket_get_name($this->stream, true);
    }

    /**
     * @return bool
     */
    public function isReadable()
    {
        return $this->isReadable && $this->stream;
    }

    /**
     * @return $this
     */
    public function close()
    {
        if ($this->isClosing) {
            return $this;
        }

        $this->flush();

        $this->isClosing = true;
        $this->isReadable = false;
        $this->isWritable = false;

        if (!$this->isEof()) {
            stream_socket_shutdown($this->stream, STREAM_SHUT_RDWR);
        }

        fclose($this->stream);

        $this->stream = null;

        return $this;
    }

    /**
     * @return bool
     */
    protected function isEof()
    {
        $info = @stream_get_meta_data($this->stream);

        return $info['eof'] || $info['timed_out'];
    }

    /**
     * @return bool
     */
    public function isWritable()
    {
        return $this->isWritable && $this->stream;
    }

    /**
     * @return bool|string
     */
    public function read()
    {
        if (!$this->isReadable()) {
            throw new \LogicException("Stream is not readable");
        }

        if (!$this->select(1)) {
            return false;
        }

        $data = @stream_get_contents($this->stream, $this->readBufferSize);

        if ($data === false || $this->isEof()) {
            $this->isReadable = false;
            $this->close();
        }

        $this->dataReceived += strlen($data);

        return $data;
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

        $result = @stream_select($read, $write, $except, $timeout);
        if ($result !== false) {
            return $result === 1;
        }

        $this->isReadable = false;
        throw new \RuntimeException("Stream select failed");
    }

    /**
     * @param string $data
     * @return $this
     */
    public function write($data)
    {
        if ($this->isWritable()) {
            $this->data .= $data;

            if (isset($this->data[$this->writeBufferSize])) {
                $this->doWrite($this->writeCallback);
            }
        }

        return $this;
    }

    /**
     * @return $this
     */
    public function flush()
    {
        $this->doWrite($this->writeCallback);

        return $this;
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
            $amount = stream_select($read, $write, $except, 1);
            $wrote = 0;
            if ($amount === 1) {
                $wrote = @$writeMethod($this->stream, $this->data);
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

    /**
     * @param string $data
     * @return $this
     */
    public function end($data = null)
    {
        if ($this->isWritable()) {
            $this->write($data);
            $this->flush();
        }

        $this->close();

        return $this;
    }
}