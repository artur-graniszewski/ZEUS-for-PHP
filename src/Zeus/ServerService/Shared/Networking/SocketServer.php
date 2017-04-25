<?php

namespace Zeus\ServerService\Shared\Networking;

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
                'backlog' => 100000,
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
     * @return null|SocketConnection
     */
    public function listen($timeout)
    {
        $newSocket = @stream_socket_accept($this->socket, $timeout);
        if ($newSocket) {
            stream_set_blocking($newSocket, false);

            return new SocketConnection($newSocket);
        }

        return null;
    }

    /**
     * @return $this
     */
    public function stop()
    {
        stream_socket_shutdown($this->socket, STREAM_SHUT_RDWR);
        fclose($this->socket);
        $this->socket = null;

        return $this;
    }
}