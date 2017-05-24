<?php

namespace Zeus\Kernel\Networking;
use Zeus\Kernel\Networking\Stream\SocketStream;

/**
 * Class SocketServer
 * @package Zeus\ServerService\Shared\Networking
 * @internal
 */
final class SocketServer
{
    protected $socket;

    protected $config;

    public function __construct($config)
    {
        $this->config = $config;
    }

    /**
     * @return $this
     */
    public function createServer()
    {
        $opts = [
            'socket' => [
                //'backlog' => 1,
                //'so_reuseport' => true,
            ],
        ];

        $context = stream_context_create($opts);

        $uri = 'tcp://' . $this->config->getListenAddress() . ':' . $this->config->getListenPort();

        $this->socket = @stream_socket_server($uri, $errno, $errstr, STREAM_SERVER_BIND|STREAM_SERVER_LISTEN, $context);
        if (false === $this->socket) {
            throw new \RuntimeException("Could not bind to $uri: $errstr", $errno);
        }

        stream_set_blocking($this->socket, 0);

        return $this;
    }

    /**
     * @param int $timeout
     * @return null|SocketStream
     */
    public function listen($timeout)
    {
        $newSocket = @stream_socket_accept($this->socket, $timeout, $peerName);
        if ($newSocket) {
            stream_set_blocking($newSocket, false);

            if (function_exists('stream_set_chunk_size')) {
                stream_set_chunk_size($newSocket, 1);
            }

            if (function_exists('stream_set_read_buffer')) {
                stream_set_read_buffer($newSocket, 0);
            }

            if (function_exists('stream_set_write_buffer')) {
                stream_set_write_buffer($newSocket, 0);
            }

            $connection = new SocketStream($newSocket, $peerName);

            return $connection;
        }

        return null;
    }

    /**
     * @return $this
     */
    public function stop()
    {
        if (!$this->socket) {
            throw new \LogicException("Server already stopped");
        }
        @stream_socket_shutdown($this->socket, STREAM_SHUT_RDWR);
        fclose($this->socket);
        $this->socket = null;

        return $this;
    }
}