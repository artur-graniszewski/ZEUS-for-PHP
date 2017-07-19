<?php

namespace Zeus\Networking\Stream;
use Zeus\Networking\Exception\SocketException;

/**
 * Class SocketStream
 * @package Zeus\ServerService\Shared\Networking
 * @internal
 */
final class SocketStream extends AbstractSelectableStream implements NetworkStreamInterface
{
    protected $peerName;

    /**
     * SocketConnection constructor.
     * @param resource $stream
     * @param string $peerName
     */
    public function __construct($stream, string $peerName = null)
    {
        $this->peerName = $peerName;

        parent::__construct($stream);

        $this->writeCallback = defined("HHVM_VERSION") ? 'fwrite' : 'fwrite';
    }

    /**
     * @param int $option
     * @param mixed $value
     * @return $this
     */
    public function setOption(int $option, $value)
    {
        if ($this->isClosed()) {
            throw new SocketException("Stream must be open");
        }

        $socket = socket_import_stream($this->getResource());
        socket_set_option($socket, SOL_SOCKET, $option, $value);

        return $this;
    }

    /**
     * @return $this
     */
    protected function doClose()
    {
        if (!$this->isEof()) {
            stream_set_blocking($this->resource, true);
            @stream_socket_shutdown($this->resource, STREAM_SHUT_RDWR);
            fread($this->resource, 4096);
        }

        parent::doClose();

        return $this;
    }

    /**
     * @return string|null Server address (IP) or null if unknown
     */
    public function getLocalAddress() : string
    {
        return @stream_socket_get_name($this->resource, false);
    }

    /**
     * @return string|null Remote address (client IP) or null if unknown
     */
    public function getRemoteAddress() : string
    {
        return $this->peerName ? $this->peerName : @stream_socket_get_name($this->resource, true);
    }
}