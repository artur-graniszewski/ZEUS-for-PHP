<?php

namespace Zeus\Networking\Stream;

use Zeus\Exception\UnsupportedOperationException;
use Zeus\Networking\Exception\SocketException;
use Zeus\Networking\Exception\StreamException;

use function stream_socket_get_name;
use function socket_import_stream;
use function socket_set_option;
use function stream_set_blocking;
use function stream_socket_shutdown;
use function fflush;
use function fclose;
use function strlen;
use function strpos;
use function substr;

/**
 * Class SocketStream
 * @package Zeus\ServerService\Shared\Networking
 * @internal
 */
class SocketStream extends AbstractSelectableStream implements NetworkStreamInterface
{
    public function __construct($resource, string $peerName = null)
    {
        parent::__construct($resource, $peerName);

        stream_set_write_buffer($resource, 0);
        stream_set_read_buffer($resource, 0);

        $this->writeCallback = 'stream_socket_sendto';
        $this->readCallback = 'stream_socket_recvfrom';
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
        while ($this->doSelect($read, $noop, $noop, 0) && strlen(@$readMethod($resource, 8192)) > 0) {
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
        return $this->peerName ? $this->peerName : (string) @stream_socket_get_name($this->resource, true);
    }

    public function shutdown(int $operation)
    {
        if ($operation === STREAM_SHUT_RD && !$this->isReadable) {
            throw new StreamException("Stream is not readable");
        }

        if ($operation === STREAM_SHUT_WR && !$this->isWritable) {
            throw new StreamException("Stream is not writable");
        }

        if ($operation === STREAM_SHUT_RDWR && !($this->isWritable && $this->isReadable)) {
            throw new StreamException("Stream is not writable or readable");
        }

        @stream_socket_shutdown($this->resource, $operation);
    }

    /**
     * @param callable $readMethod
     * @param string $ending
     * @return string
     */
    protected function doRead($readMethod, string $ending = '') : string
    {
        $data = '';
        if (!$this->isReadable) {
            throw new StreamException("Stream is not readable");
        }

        if ($ending === '') {
            if ($this->select(0)) {
                $data = @$readMethod($this->resource, $this->readBufferSize);

                if (false === $data || '' === $data) {
                    // select() reported stream to have some data to read but buffer was empty, this means:
                    // "When a socket is at EOF, read returns without blocking (a zero-length read).
                    // This is basically the definition (from an application standpoint) of EOF."

                    $this->isReadable = false;
                    throw new StreamException("Stream is not readable");
                } else {
                    $this->dataReceived += strlen($data);
                }
            }

            return $data;
        }

        // @todo: buffer internally until ending is found, return false until ending is found
        $data = '';
        $endingSize = strlen($ending);

        while (!$this->isEof() && $this->select(0)) {
            // @todo: add some checks if STREAM_PEEK is supported by $readMethod
            $buffer = @$readMethod($this->resource, $this->readBufferSize, STREAM_PEEK);

            if ($buffer === '' || $buffer === false) {
                // stream had some data to read but buffer was empty, this is an EOF situation
                $this->isReadable = false;
                throw new StreamException("Stream is not readable");
            }

            $pos = strpos($buffer, $ending);
            if (false !== $pos) {
                $buffer = substr($buffer, 0, $pos);
                $pos += $endingSize;
            } else {
                $pos = strlen($buffer);
            }

            @$readMethod($this->resource, $pos);

            $data .= $buffer;

            break;
        }

        $this->dataReceived += strlen($data);

        return $data === false ? '' : $data;
    }
}