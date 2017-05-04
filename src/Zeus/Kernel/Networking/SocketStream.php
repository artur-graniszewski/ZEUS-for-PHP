<?php

namespace Zeus\Kernel\Networking;

/**
 * Class SocketConnection
 * @package Zeus\ServerService\Shared\Networking
 * @internal
 */
final class SocketStream extends FileStream implements ConnectionInterface, FlushableConnectionInterface
{
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
     * @return $this
     */
    protected function doClose()
    {
        if (!$this->isEof()) {
            stream_socket_shutdown($this->stream, STREAM_SHUT_RDWR);
        }

        parent::doClose();

        return $this;
    }
}