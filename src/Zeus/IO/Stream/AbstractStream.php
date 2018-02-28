<?php

namespace Zeus\IO\Stream;

use OutOfRangeException;
use Zeus\IO\Exception\IOException;

use function preg_match;
use function strlen;
use function substr;
use function stream_set_blocking;
use function error_clear_last;
use function error_get_last;
use function stream_get_meta_data;
use function stream_get_line;
use function function_exists;
use function fclose;
use function fflush;
use function ftell;
use function feof;

/**
 * Class AbstractStream
 * @package Zeus\ServerService\Shared\Networking
 * @internal
 */
class AbstractStream extends AbstractPhpResource implements StreamInterface
{
    const DEFAULT_WRITE_BUFFER_SIZE = 65536;
    const DEFAULT_READ_BUFFER_SIZE = 65536;

    protected $isWritable = false;

    protected $isReadable = false;

    protected $isClosed = false;

    protected $writeBuffer = '';

    protected $writeBufferSize = self::DEFAULT_WRITE_BUFFER_SIZE;

    protected $readBufferSize = self::DEFAULT_READ_BUFFER_SIZE;

    protected $dataSent = 0;

    protected $dataReceived = 0;

    protected $writeCallback = 'fwrite';

    protected $readCallback = 'stream_get_contents';

    protected $peerName = '';

    protected $isBlocking = true;

    /**
     * SocketConnection constructor.
     * @param resource $resource
     * @param string $peerName
     */
    public function __construct($resource, string $peerName = null)
    {
        parent::__construct($resource, $peerName);
        $this->peerName = $peerName;
        $this->detectResourceMode();
    }

    protected function detectResourceMode()
    {
        $meta = stream_get_meta_data($this->getResource());
        $mode = $meta['mode'];
        if (preg_match('~([waxc]|r\+)~', $mode)) {
            $this->isWritable = true;
        }

        if (preg_match('~(r|r\+|w\+|x\+|a\+|c\+)~', $mode)) {
            $this->isReadable = true;
        }
    }

    public function isReadable() : bool
    {
        return $this->isReadable && $this->resource && ($this->isReadable = !$this->isEof());
    }

    public function isBlocking() : bool
    {
        return $this->isBlocking;
    }

    public function setBlocking(bool $isBlocking)
    {
        if (function_exists('error_clear_last')) {
            error_clear_last();
        };
        $result = @stream_set_blocking($this->resource, $isBlocking);

        if (!$result) {
            $error = error_get_last();
            $message = $error ? str_replace("stream_set_blocking(): ", '', $error['message']) : 'unknown error';
            throw new IOException("Failed to switch the stream to a " . (!$isBlocking ? "non-" : "") . "blocking mode: " . $message);
        }

        $this->isBlocking = $isBlocking;
    }

    /**
     * @throws \Throwable
     */
    public function close()
    {
        if ($this->isClosed || !is_resource($this->resource)) {
            throw new IOException("Stream already closed");
        }

        $exception = null;

        $this->isReadable = false;
        $this->isWritable = false;
        $this->isClosed = true;

        try {
            $this->doClose();
        } catch (\Exception $exception) {

        }

        $this->resource = null;

        if ($exception) {
            throw $exception;
        }
    }

    public function isClosed() : bool
    {
        return $this->isClosed || !is_resource($this->resource);
    }

    protected function doClose()
    {
        fflush($this->resource);
        fclose($this->resource);
    }

    protected function isEof() : bool
    {
        // curious, if stream_get_meta_data() is executed before feof(), then feof() result will be altered and may lie
        if (@feof($this->resource)) {
            return true;
        }
        $info = @stream_get_meta_data($this->resource);

        return $info['eof'] || $info['timed_out'];
    }

    public function isWritable() : bool
    {
        return $this->isWritable && $this->resource;
    }

    public function read(int $size = 0) : string
    {
        return $this->doRead($this->readCallback, $size);
    }

    /**
     * @param callable $readMethod
     * @param int $size
     * @return string
     */
    protected function doRead($readMethod, int $size = 0) : string
    {
        if (!$this->isReadable() || $this->isEof()) {
            throw new IOException("Stream is not readable");
        }

        $data = @$readMethod($this->resource, $size ? $size : $this->readBufferSize);
        $this->dataReceived += strlen($data);

        return $data === false ? '' : $data;
    }

    public function write(string $data) : int
    {
        if (!$this->isWritable()) {
            throw new IOException("Stream is not writable");
        }

        $this->writeBuffer .= $data;

        if (!$this->writeBufferSize || isset($this->writeBuffer[$this->writeBufferSize])) {
            return $this->doWrite($this->writeCallback);
        } else {
            return 0;
        }
    }

    public function flush() : bool
    {
        $this->doWrite($this->writeCallback);

        return !isset($this->writeBuffer[0]);
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
            $wrote = $writeMethod($this->resource, $this->writeBuffer);
            if ($wrote < 0 || false === $wrote) {
                $this->isWritable = false;
                break;
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

    public function setWriteBufferSize(int $size)
    {
        if ($size < 0) {
            throw new OutOfRangeException("Write buffer size must be greater than or equal 0");
        }
        $this->writeBufferSize = $size;
    }

    public function setReadBufferSize(int $size)
    {
        if ($size <= 0) {
            throw new OutOfRangeException("Read buffer size must be greater than 0");
        }
        $this->readBufferSize = $size;
    }
}