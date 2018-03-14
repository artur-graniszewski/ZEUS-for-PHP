<?php

namespace Zeus\IO\Stream;

use Zeus\Exception\UnsupportedOperationException;
use Zeus\IO\Exception\SocketException;
use Zeus\IO\Exception\IOException;

use function stream_socket_get_name;
use function stream_select;
use function socket_import_stream;
use function socket_set_option;
use function stream_set_blocking;
use function stream_socket_shutdown;
use function stream_set_write_buffer;
use function stream_set_read_buffer;
use function function_exists;
use function fflush;
use function fclose;
use function strlen;
use function in_array;

/**
 * Class SocketStream
 * @package Zeus\ServerService\Shared\Networking
 * @internal
 */
class SocketStream extends AbstractSelectableStream implements NetworkStreamInterface
{
    /** @var int */
    private $soTimeout = 1000;

    public function __construct($resource, string $peerName = null)
    {
        parent::__construct($resource, $peerName);

        stream_set_write_buffer($resource, 0);
        stream_set_read_buffer($resource, 0);

        $this->writeCallback = 'stream_socket_sendto';
        $this->readCallback = 'stream_socket_recvfrom';
    }

    protected function detectResourceMode()
    {
        $this->isReadable = true;
        $this->isWritable = true;
    }

    /**
     * @param int $option
     * @param mixed $value
     */
    public function setOption(int $option, $value)
    {
        if ($this->isClosed()) {
            throw new SocketException("Stream must be open");
        }

        $level = \SOL_SOCKET;

        if (in_array($option, [\TCP_NODELAY])) {
            $level = \SOL_TCP;
        }

        if (!function_exists('socket_import_stream') || !function_exists('socket_set_option')) {
            throw new UnsupportedOperationException("This option is unsupported by current PHP configuration");
        }

        $socket = socket_import_stream($this->getResource());
        socket_set_option($socket, $level, $option, $value);
    }

    protected function doClose()
    {
        $resource = $this->resource;
        $readMethod = $this->readCallback;
        fflush($resource);
        stream_socket_shutdown($resource, STREAM_SHUT_RD);
        stream_set_blocking($resource, false);
        $read = [$this->resource];
        $noop = [];
        while (@stream_select($read, $noop, $noop, 0) && strlen(@$readMethod($resource, 8192)) > 0) {
            // read...
        };
        fclose($resource);
    }

    public function isReadable() : bool
    {
        return $this->isReadable && $this->resource;
    }

    /**
     * @return string Remote address (client IP) or '' if unknown
     */
    public function getRemoteAddress() : string
    {
        return $this->peerName ? $this->peerName : $this->peerName = (string) @stream_socket_get_name($this->resource, true);
    }

    public function shutdown(int $operation)
    {
        if ($operation === STREAM_SHUT_RD || $operation === STREAM_SHUT_RDWR) {
            if (!$this->isReadable) {
                throw new IOException("Stream is not readable");
            }

            $this->isReadable = false;
        }

        if ($operation === STREAM_SHUT_WR || $operation === STREAM_SHUT_RDWR) {
            if(!$this->isWritable) {
                throw new IOException("Stream is not writable");
            }

            $this->isWritable = false;
        }

        @stream_socket_shutdown($this->resource, $operation);
    }

    /**
     * @param callable $readMethod
     * @param int $size
     * @return string
     */
    protected function doRead($readMethod, int $size = 0) : string
    {
        if (!$this->isReadable) {
            throw new IOException("Stream is not readable");
        }

        $data = @$readMethod($this->resource, $size ? $size : $this->readBufferSize);

        if (false === $data) {
            $this->isReadable = false;
            throw new IOException("Stream is not readable");
        } else {
            $this->dataReceived += strlen($data);
        }

        return $data;
    }

    public function getSoTimeout() : int
    {
        return $this->soTimeout;
    }

    public function setSoTimeout(int $milliseconds)
    {
        $this->soTimeout = $milliseconds;
    }
}