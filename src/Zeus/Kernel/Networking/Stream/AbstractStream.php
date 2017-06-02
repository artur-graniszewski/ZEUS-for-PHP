<?php

namespace Zeus\Kernel\Networking\Stream;
use Zeus\Kernel\Networking\Stream\NetworkStreamInterface;
use Zeus\Kernel\Networking\Stream\FlushableConnectionInterface;

/**
 * Class AbstractStream
 * @package Zeus\ServerService\Shared\Networking
 * @internal
 */
class AbstractStream implements StreamInterface, FlushableConnectionInterface
{
    const DEFAULT_WRITE_BUFFER_SIZE = 65536;
    const DEFAULT_READ_BUFFER_SIZE = 65536;

    protected $isWritable = true;

    protected $isReadable = true;

    protected $isClosing = false;

    protected $stream;

    protected $writeBuffer = '';

    protected $readBuffer = '';

    protected $writeBufferSize = self::DEFAULT_WRITE_BUFFER_SIZE;

    protected $readBufferSize = self::DEFAULT_READ_BUFFER_SIZE;

    protected $dataSent = 0;

    protected $dataReceived = 0;

    protected $writeCallback = 'fwrite';

    /**
     * SocketConnection constructor.
     * @param resource $stream
     * @param string $peerName
     */
    public function __construct($stream, $peerName = null)
    {
        $this->stream = $stream;
        $this->peerName = $peerName;
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

        $this->doClose();

        return $this;
    }

    /**
     * @return $this
     */
    protected function doClose()
    {
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
        // curious, if stream_get_meta_data() is executed before feof(), then feof() result will be altered and may lie
        if (feof($this->stream)) {
            return true;
        }
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

        if ($ending !== false) {
            $data = '';
            $endingSize = strlen($ending);

            while (!$this->isEof()) {
                $pos = ftell($this->stream);

                $buffer = @stream_get_line($this->stream, $this->readBufferSize, $ending);
                $data .= $buffer;

                $newPos = ftell($this->stream);
                if ($newPos === $pos + strlen($buffer) + $endingSize) {

                    break;
                }
                break;
            }

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
            $wrote = $writeMethod($this->stream, $this->writeBuffer);

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