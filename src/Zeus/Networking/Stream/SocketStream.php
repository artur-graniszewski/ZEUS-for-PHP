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
     * @param resource $resource
     * @param string $peerName
     */
    public function __construct($resource, string $peerName = null)
    {
        $this->peerName = $peerName;

        parent::__construct($resource);

        $this->writeCallback = defined("HHVM_VERSION") ? 'fwrite' : 'stream_socket_sendto';
        $this->readCallback = 'stream_socket_recvfrom';
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
        $resource = $this->resource;

        $readMethod = $this->readCallback;
        \stream_set_blocking($resource, true);
        \fflush($resource);
        @stream_socket_shutdown($resource, STREAM_SHUT_RDWR);
        \stream_set_blocking($resource, false);
        @$readMethod($resource, 4096);
        \fclose($resource);

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