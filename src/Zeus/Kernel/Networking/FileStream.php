<?php

namespace Zeus\Kernel\Networking;

/**
 * Class SocketConnection
 * @package Zeus\ServerService\Shared\Networking
 * @internal
 */
class FileStream implements ConnectionInterface, FlushableConnectionInterface
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

    protected $writeCallback = 'fwrite';

    /**
     * SocketConnection constructor.
     * @param resource $stream
     */
    public function __construct($stream)
    {
        $this->stream = $stream;
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

        if ($ending !== false) {
            $data = @stream_get_line($this->stream, $this->readBufferSize, $ending);
        } else {
            $data = @stream_get_contents($this->stream, $this->readBufferSize);
        }

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
            $amount = 1;
            $wrote = $writeMethod($this->stream, $this->data);

            // write failed, try to wait a bit
            if ($wrote === 0) {
                do {
                    $amount = @stream_select($read, $write, $except, 1);
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

    /**
     * @param int $size
     * @return $this
     */
    public function setWriteBufferSize($size)
    {
        $this->writeBufferSize = $size;

        return $this;
    }

    /**
     * @param int $size
     * @return $this
     */
    public function setReadBufferSize($size)
    {
        $this->readBufferSize = $size;

        return $this;
    }
}