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

    protected $bufferSize = 81920;

    public function __construct($stream)
    {
        $this->stream = $stream;

        stream_set_blocking($this->stream, 0);

        if (function_exists('stream_set_read_buffer')) {
            stream_set_read_buffer($this->stream, 0);
            stream_set_write_buffer($this->stream, 0);
        }
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

        stream_set_blocking($this->stream, true);
        stream_socket_shutdown($this->stream, STREAM_SHUT_RDWR);
//        stream_set_blocking($this->stream, false);
        fclose($this->stream);

        $this->stream = null;

        return $this;
    }

    /**
     * @return bool
     */
    protected function isEof()
    {
        $info = stream_get_meta_data($this->stream);

        return $info['eof'] || $info['timed_out'];
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

        $error = null;
        set_error_handler(function ($errno, $errstr, $errfile, $errline) use (&$error) {
            $error = new \ErrorException(
                $errstr,
                0,
                $errno,
                $errfile,
                $errline
            );
        });

        $data = stream_get_contents($this->stream, $this->bufferSize);

        restore_error_handler();

        if ($error !== null || $this->isEof()) {
            $this->close();

            return false;
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

        $result = @stream_select($read, $write, $except, $timeout);
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
            $this->data = '';
            return $this;
        }

        $size = strlen($data);
        $sent = 0;

        while ($sent !== $size) {
            $wrote = @stream_socket_sendto($this->stream, $data);
            fflush($this->stream);
            if ($wrote < 0) {
                $this->isWritable = false;
                $this->isReadable = false;// remove this?
//                throw new \LogicException("Write to stream failed");
                $this->close();
                break;
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