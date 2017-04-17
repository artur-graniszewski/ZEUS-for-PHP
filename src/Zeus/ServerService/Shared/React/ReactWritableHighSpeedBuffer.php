<?php

namespace Zeus\ServerService\Shared\React;

use React\EventLoop\LoopInterface;
use React\Stream\Buffer;

class ReactWritableHighSpeedBuffer extends Buffer
{
    const MAX_SOFT_LIMIT = 6000000000;
    protected $data = '';

    protected $loop;

    public function __construct($stream, LoopInterface $loop)
    {
        // @todo: remove this hack and use file streaming instead...
        $this->softLimit = static::MAX_SOFT_LIMIT;
        parent::__construct($stream, $loop);

        $this->loop = $loop;
    }

    /**
     * @param string $data
     * @return bool
     */
    public function write($data)
    {
        if (!$this->isWritable()) {
            return false;
        }

        $this->data .= $data;

        return parent::write($data);
    }

    /**
     *
     */
    public function close()
    {
        $this->data = '';

        return parent::close();
    }

    public function handleWrite()
    {
        $error = null;
        set_error_handler(function ($errno, $errstr, $errfile, $errline) use (&$error) {
            $error = array(
                'message' => $errstr,
                'number' => $errno,
                'file' => $errfile,
                'line' => $errline
            );
        });

        $sent = defined('HHVM_VERSION') ?
            // HHVM
            fwrite($this->stream, $this->data)
            :
            // PHP
            stream_socket_sendto($this->stream, $this->data);

        restore_error_handler();

        // Only report errors if *nothing* could be sent.
        // Any hard (permanent) error will fail to send any data at all.
        // Sending excessive amounts of data will only flush *some* data and then
        // report a temporary error (EAGAIN) which we do not raise here in order
        // to keep the stream open for further tries to write.
        // Should this turn out to be a permanent error later, it will eventually
        // send *nothing* and we can detect this.
        if ($sent === 0 || $sent === false) {
            $error =
                $error === null ?
                new \RuntimeException('Send failed')
                :
                new \ErrorException(
                    $error['message'],
                    0,
                    $error['number'],
                    $error['file'],
                    $error['line']
                );

            $this->emit('error', array(new \RuntimeException('Unable to write to stream: ' . $error->getMessage(), 0, $error), $this));
            $this->close();

            return;
        }

        $exceeded = isset($this->data[$this->softLimit - 1]);
        $this->data = (string) substr($this->data, $sent);

        // buffer has been above limit and is now below limit
        if ($exceeded && !isset($this->data[$this->softLimit - 1])) {
            $this->emit('drain', array($this));
        }

        // buffer is now completely empty (and not closed already)
        if ($this->data === '' && $this->listening) {
            $this->loop->removeWriteStream($this->stream);
            $this->listening = false;

            $this->emit('full-drain', array($this));
        }
    }
}