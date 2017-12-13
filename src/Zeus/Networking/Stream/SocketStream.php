<?php

namespace Zeus\Networking\Stream;

use Zeus\Networking\Exception\SocketException;
use Zeus\Networking\Exception\StreamException;

use function stream_socket_get_name;
use function socket_import_stream;
use function socket_set_option;
use function stream_set_blocking;
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
    /**
     * SocketConnection constructor.
     * @param resource $resource
     * @param string $peerName
     */
    public function __construct($resource, string $peerName = null)
    {
        parent::__construct($resource, $peerName);

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
            throw new \RuntimeException("This option is unsupported by current PHP configuration");
        }

        $socket = socket_import_stream($this->getResource());
        socket_set_option($socket, $level, $option, $value);
    }

    protected function doClose()
    {
        $resource = $this->resource;

        $readMethod = $this->readCallback;
        fflush($resource);
        stream_socket_shutdown($resource, STREAM_SHUT_RDWR);
        stream_set_blocking($resource, false);
        while (strlen(@$readMethod($resource, 8192)) > 0) {
            // read...
        };
        fclose($resource);
    }

    /**
     * @return string|null Remote address (client IP) or null if unknown
     */
    public function getRemoteAddress() : string
    {
        return $this->peerName ? $this->peerName : @stream_socket_get_name($this->resource, true);
    }

    /**
     * @param callable $readMethod
     * @param string $ending
     * @return string
     */
    protected function doRead($readMethod, string $ending = '') : string
    {
        if (!$this->isReadable) {
            throw new StreamException("Stream is not readable");
        }

        if ($ending === '') {
            $data = @$readMethod($this->resource, $this->readBufferSize);

            if ((false === $data || "" == $data) && $this->isEof()) {
                $this->isReadable = false;
                throw new StreamException("Stream is not readable");
            } else {
                $this->dataReceived += strlen($data);
            }

            return $data;
        }

        // @todo: buffer internally until ending is found, return false until ending is found
        $data = '';
        $endingSize = strlen($ending);

        while (!$this->isEof() && $this->select(0)) {
            // @todo: add some checks if STREAM_PEEK is supported by $readMethod
            $buffer = @$readMethod($this->resource, $this->readBufferSize, STREAM_PEEK);

            if ($buffer === '') {
                @$readMethod($this->resource, 0);
                break;
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