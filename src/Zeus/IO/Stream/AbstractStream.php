<?php

namespace Zeus\IO\Stream;

use OutOfRangeException;
use Zeus\IO\Exception\IOException;

use function is_resource;
use function preg_match;
use function strlen;
use function substr;
use function stream_set_blocking;
use function error_clear_last;
use function error_get_last;
use function stream_get_meta_data;
use function function_exists;
use function fclose;
use function fflush;
use function feof;
use function str_replace;

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

    private $isClosed = false;

    protected $writeBuffer = '';

    private $writeBufferSize = self::DEFAULT_WRITE_BUFFER_SIZE;

    private $readBufferSize = self::DEFAULT_READ_BUFFER_SIZE;

    private $dataSent = 0;

    private $dataReceived = 0;

    protected $writeCallback = 'fwrite';

    protected $readCallback = 'stream_get_contents';

    protected $peerName = '';

    private $isBlocking = true;

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
        } catch (IOException $exception) {

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
        $data = $this->doRead($this->readCallback, $size);
        $this->dataReceived += strlen($data);

        return $data;
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

        return $data === false ? '' : $data;
    }

    public function write(string $data) : int
    {
        if (!$this->isWritable()) {
            throw new IOException("Stream is not writable");
        }

        $this->writeBuffer .= $data;

        if (!$this->writeBufferSize || isset($this->writeBuffer[$this->writeBufferSize])) {
            $sent = $this->doWrite($this->writeCallback);
            $this->dataSent += $sent;
            return $sent;
        }

        return 0;
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
        if ($this->isEof()) {
            $this->isWritable = false;
            throw new IOException(sprintf("Stream is not writable"));
        }
        $size = strlen($this->writeBuffer);
        $sent = 0;

        $wrote = @$writeMethod($this->resource, $this->writeBuffer);
        if ($wrote < 0 || false === $wrote) {
            $this->isWritable = false;

            throw new IOException(sprintf("Stream is not writable, wrote %d bytes out of %d", max(0, $sent), $size));
        }

        if ($wrote) {
            $sent += $wrote;
            $this->writeBuffer = substr($this->writeBuffer, $wrote);
        }

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

    public function getReadBufferSize() : int
    {
        return $this->readBufferSize;
    }

    public function getWriteBufferSize() : int
    {
        return $this->writeBufferSize;
    }
}