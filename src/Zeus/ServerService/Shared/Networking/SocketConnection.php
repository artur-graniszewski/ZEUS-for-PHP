<?php

namespace Zeus\ServerService\Shared\Networking;

use React\Stream\WritableStreamInterface;

class SocketConnection implements ConnectionInterface, FlushableConnectionInterface
{
    protected $isWritable = true;

    protected $isReadable = true;

    protected $isClosing = false;

    protected $stream;

    protected $data;

    protected $bufferSize = 65536;

    public function __construct($stream)
    {
        $this->stream = $stream;
        /*
        stream_set_blocking($stream, false);
        if (function_exists('stream_set_read_buffer')) {
            stream_set_read_buffer($this->stream, 0);
        }

        if (function_exists('stream_set_write_buffer')) {
            stream_set_write_buffer($this->stream, 0);
        }

        stream_set_chunk_size($this->stream, 1);
        */
    }

    public function getServerAddress()
    {
        return '127.0.0.1'; //@stream_socket_get_name($this->stream, false);
    }

    /**
     * Returns the remote address (client IP) where this connection has been established from
     *
     * @return string|null remote address (client IP) or null if unknown
     */
    public function getRemoteAddress()
    {
        return '127.0.0.1'; //@stream_socket_get_name($this->stream, true);
    }

    public function on($event, callable $listener)
    {
        // TODO: Implement on() method.
    }

    public function once($event, callable $listener)
    {
        // TODO: Implement once() method.
    }

    public function removeListener($event, callable $listener)
    {
        // TODO: Implement removeListener() method.
    }

    public function removeAllListeners($event = null)
    {
        // TODO: Implement removeAllListeners() method.
    }

    public function listeners($event)
    {
        // TODO: Implement listeners() method.
    }

    public function emit($event, array $arguments = [])
    {
        // TODO: Implement emit() method.
    }

    public function isReadable()
    {
        return $this->isReadable;
    }

    public function pause()
    {
        // TODO: Implement pause() method.
    }

    public function resume()
    {
        // TODO: Implement resume() method.
    }

    public function pipe(WritableStreamInterface $dest, array $options = array())
    {
        // TODO: Implement pipe() method.
    }

    public function close()
    {
        if ($this->isClosing) {
            return $this;
        }

        if ($this->isWritable()) {
            $this->flush();
        }

        $this->isClosing = true;
        $this->isReadable = false;
        $this->isWritable = false;

//        $write = [$this->stream];
//        $read = $except = [];
//        stream_set_blocking($this->stream, true);
//        stream_select($read, $write, $except, null);

//        socket_send($this->stream, ' ', 0, MSG_EOF);
        socket_shutdown($this->stream, STREAM_SHUT_RDWR);

        //socket_shutdown($this->stream, STREAM_SHUT_WR);
        socket_close($this->stream);
        //stream_set_blocking($this->stream, false);
        //stream_get_contents($this->stream);
        //fclose($this->stream);

        $this->stream = null;

        return $this;
    }

    public function isWritable()
    {
        return $this->isWritable;
    }

    public function read()
    {
        if (!$this->isReadable()) {
            throw new \LogicException("Stream is not readable");
        }

        $data = socket_read($this->stream, $this->bufferSize);

        if (!is_resource($this->stream)) {
            $this->close();
        }

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

        $result = socket_select($read, $write, $except, $timeout);
        if ($result === false) {
            $this->isReadable = false;
            throw new \RuntimeException("Stream select failed");
        }

        return $result === 1;
    }

    public function write($data)
    {
        if (!$this->isClosing) {
            $this->data .= $data;

            //$this->doWrite();
            if (isset($this->data[$this->bufferSize])) {
                $this->doWrite();
            }
        }

        return $this;
    }

    public function flush()
    {
        if (!$this->isClosing) {
            $this->doWrite();
        }

        return $this;
    }

    protected function doWrite()
    {
        $data = $this->data;

        if (!$this->isWritable()) {
            throw new \LogicException("Stream is not writable");
        }

        $size = strlen($data);
        $sent = 0;

        while ($sent < $size && $data) {
            //$socket = socket_import_stream($this->stream);
            $wrote = socket_send($this->stream, $data, strlen($data), MSG_EOF);
            //$wrote = stream_socket_sendto($this->stream, $data);

            if ($wrote === false || $wrote < 0 || !is_resource($this->stream)) {
                $this->isWritable = false;
                $this->isReadable = false;// remove this?
                throw new \LogicException("Write to stream failed");
            }

            $sent += $wrote;
            $data = substr($data, $wrote);
        };

        $this->data = '';

        return $this;
    }

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