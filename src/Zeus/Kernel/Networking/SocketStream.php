<?php

namespace Zeus\Kernel\Networking;

/**
 * Class SocketConnection
 * @package Zeus\ServerService\Shared\Networking
 * @internal
 */
final class SocketStream extends FileStream implements ConnectionInterface, FlushableConnectionInterface
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
        parent::__construct($stream);

        $this->writeCallback = defined("HHVM_VERSION") ? 'fwrite' : 'stream_socket_sendto';
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
    public function isWritable()
    {
        return $this->isWritable && $this->stream;
    }

}