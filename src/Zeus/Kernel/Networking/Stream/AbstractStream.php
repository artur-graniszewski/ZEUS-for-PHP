<?php

namespace Zeus\Kernel\Networking\Stream;

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

    protected $isClosing = false;

    protected $isClosed = false;

    protected $writeBuffer = '';

    protected $readBuffer = '';

    protected $writeBufferSize = self::DEFAULT_WRITE_BUFFER_SIZE;

    protected $readBufferSize = self::DEFAULT_READ_BUFFER_SIZE;

    protected $dataSent = 0;

    protected $dataReceived = 0;

    protected $writeCallback = 'fwrite';

    protected $peerName;

    /**
     * SocketConnection constructor.
     * @param resource $stream
     * @param string $peerName
     */
    public function __construct($stream, $peerName = null)
    {
        $this->setResource($stream);
        $this->peerName = $peerName;
    }

    /**
     * @return bool
     */
    public function isReadable()
    {
        return $this->isReadable && $this->resource;
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

        $this->doClose();

        $this->isClosed = true;

        return $this;
    }

    /**
     * @return bool
     */
    public function isClosed()
    {
        return $this->isClosed;
    }

    /**
     * @return $this
     */
    protected function doClose()
    {
        $this->isClosing = true;
        $this->isReadable = false;
        $this->isWritable = false;

        fclose($this->resource);

        $this->resource = null;

        return $this;
    }

    /**
     * @return bool
     */
    protected function isEof()
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
    public function isWritable()
    {
        return $this->isWritable && $this->resource;
    }

    /**
     * @param bool|string $ending
     * @return bool|string
     */
    public function read($ending = false)
    {
        if (!$this->isReadable()) {
            throw new \LogicException("Stream is not readable");
        }

        if ($ending !== false) {
            $data = '';
            $endingSize = strlen($ending);

            while (!$this->isEof()) {
                $pos = ftell($this->resource);

                $buffer = @stream_get_line($this->resource, $this->readBufferSize, $ending);
                $data .= $buffer;

                $newPos = ftell($this->resource);
                if ($newPos === $pos + strlen($buffer) + $endingSize) {

                    break;
                }
                break;
            }

        } else {
            $data = @stream_get_contents($this->resource, $this->readBufferSize);
        }

        if ($data === false || $this->isEof()) {
            $this->isReadable = false;
            $this->close();
        }

        $this->dataReceived += strlen($data);

        return $data;
    }

    /**
     * @param string $data
     * @return $this
     */
    public function write($data)
    {
        if ($this->isWritable()) {
            $this->writeBuffer .= $data;

            if (isset($this->writeBuffer[$this->writeBufferSize])) {
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
            $this->writeBuffer = '';

            return $this;
        }

        $size = strlen($this->writeBuffer);
        $sent = 0;

        while ($sent !== $size) {
            $amount = 1;
            $wrote = $writeMethod($this->resource, $this->writeBuffer);

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