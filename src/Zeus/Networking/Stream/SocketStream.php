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
final class SocketStream extends AbstractSelectableStream implements NetworkStreamInterface
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

        // @todo: why the below function does not work with phpunit tests?
        // @todo: check performance impact of using stream_get_contents
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
        stream_set_blocking($resource, true);
        fflush($resource);
        @stream_socket_shutdown($resource, STREAM_SHUT_RDWR);
        stream_set_blocking($resource, false);
        @$readMethod($resource, 4096);
        fclose($resource);

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

    /**
     * @param callable $readMethod
     * @param string $ending
     * @return string
     */
    protected function doRead($readMethod, string $ending = '') : string
    {
        if ($ending === '') {
            $data = @$readMethod($this->resource, $this->readBufferSize);

            if (false === $data || "" === $data) {
                if (!$this->isReadable()) {
                    throw new StreamException("Stream is not readable");
                }

                $data = '';
            } else {
                $this->dataReceived += strlen($data);
            }

            return $data;
        }

        if (!$this->isReadable()) {
            throw new StreamException("Stream is not readable");
        }

        // @todo: buffer internally until ending is found, return false until ending is found
        $data = '';
        $endingSize = strlen($ending);

        while (!$this->isEof()) {
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