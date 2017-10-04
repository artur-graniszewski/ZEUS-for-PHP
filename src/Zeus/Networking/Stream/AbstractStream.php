<?php

namespace Zeus\Networking\Stream;

use Zeus\Networking\Exception\StreamException;

use function strlen;
use function substr;
use function stream_set_blocking;
use function stream_get_meta_data;
use function stream_get_line;
use function fclose;
use function fflush;
use function ftell;
use function feof;

/**
 * Class AbstractStream
 * @package Zeus\ServerService\Shared\Networking
 * @internal
 */
class AbstractStream extends AbstractPhpResource implements StreamInterface, FlushableConnectionInterface
{
    const DEFAULT_WRITE_BUFFER_SIZE = 65536;
    const DEFAULT_READ_BUFFER_SIZE = 65536;

    protected $isWritable = true;

    protected $isReadable = true;

    protected $isClosed = false;

    protected $writeBuffer = '';

    protected $writeBufferSize = self::DEFAULT_WRITE_BUFFER_SIZE;

    protected $readBufferSize = self::DEFAULT_READ_BUFFER_SIZE;

    protected $dataSent = 0;

    protected $dataReceived = 0;

    protected $writeCallback = 'fwrite';

    protected $readCallback = 'stream_get_contents';

    protected $peerName;

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
    }

    /**
     * @return bool
     */
    public function isReadable() : bool
    {
        return $this->isReadable && $this->resource && ($this->isReadable = !$this->isEof());
    }

    public function isBlocking() : bool
    {
        return $this->isBlocking;
    }

    /**
     * @param bool $isBlocking
     * @return $this
     */
    public function setBlocking(bool $isBlocking)
    {
        $result = stream_set_blocking($this->resource, $isBlocking);

        if (!$result) {
            throw new StreamException("Failed to switch the stream to a " . (!$isBlocking ? "non-" : "") . "blocking mode");
        }

        $this->isBlocking = $isBlocking;

        return $this;
    }

    /**
     * @return $this
     * @throws \Exception
     */
    public function close()
    {
        if ($this->isClosed) {
            throw new StreamException("Stream already closed");
        }

        $exception = null;
        try {
            $this->flush();
        } catch (\Exception $exception) {

        }

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

        return $this;
    }

    /**
     * @return bool
     */
    public function isClosed() : bool
    {
        return $this->isClosed;
    }

    /**
     * @return $this
     */
    protected function doClose()
    {
        fflush($this->resource);
        fclose($this->resource);

        return $this;
    }

    /**
     * @return bool
     */
    protected function isEof() : bool
    {
        // curious, if stream_get_meta_data() is executed before feof(), then feof() result will be altered and may lie
        if (feof($this->resource)) {
            return true;
        }
        $info = @stream_get_meta_data($this->resource);

        return $info['eof'] || $info['timed_out'];
    }

    /**
     * @return bool
     */
    public function isWritable() : bool
    {
        return $this->isWritable && $this->resource;
    }

    /**
     * @param string $ending
     * @return string|false false if nothing was read, string otherwise
     */
    public function read(string $ending = '') : string
    {
        return $this->doRead($this->readCallback, $ending);
    }

    /**
     * @param callable $readMethod
     * @param string $ending
     * @return mixed
     */
    protected function doRead($readMethod, string $ending = '') : string
    {
        if (!$this->isReadable()) {
            throw new StreamException("Stream is not readable");
        }

        if ($ending !== '') {
            $data = '';
            $endingSize = strlen($ending);

            while (!$this->isEof()) {
                $pos = ftell($this->resource);

                // @todo: replace this function, as it uses PHP buffers which collide with STREAM_PEEK behaviour
                $buffer = @stream_get_line($this->resource, $this->readBufferSize, $ending);

                if ($buffer === '') {
                    break;
                }

                $data .= $buffer;

                $newPos = ftell($this->resource);
                if ($newPos === $pos + strlen($buffer) + $endingSize) {

                    break;
                }
                break;
            }

        } else {
            $data = @$readMethod($this->resource, $this->readBufferSize);
        }

        $this->dataReceived += strlen($data);

        return $data === false ? '' : $data;
    }

    /**
     * @param string $data
     * @return $this
     */
    public function write(string $data)
    {
        if (!$this->isWritable()) {
            throw new StreamException("Stream is not writable");
        }

        $this->writeBuffer .= $data;

        if (isset($this->writeBuffer[$this->writeBufferSize])) {
            $this->doWrite($this->writeCallback);
        }

        return $this;
    }

    /**
     * @return $this
     */
    public function flush()
    {
        $this->doWrite($this->writeCallback);

        if (isset($this->writeBuffer[0])) {
            throw new StreamException(sprintf("Flush incomplete, %d bytes left in the write buffer", strlen($this->writeBuffer)));
        }

        return $this;
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

    /**
     * @param int $size
     * @return $this
     */
    public function setWriteBufferSize(int $size)
    {
        if ($size < 0) {
            throw new \OutOfRangeException("Write buffer size must be greater than or equal 0");
        }
        $this->writeBufferSize = $size;

        return $this;
    }

    /**
     * @param int $size
     * @return $this
     */
    public function setReadBufferSize(int $size)
    {
        if ($size <= 0) {
            throw new \OutOfRangeException("Read buffer size must be greater than 0");
        }
        $this->readBufferSize = $size;

        return $this;
    }
}