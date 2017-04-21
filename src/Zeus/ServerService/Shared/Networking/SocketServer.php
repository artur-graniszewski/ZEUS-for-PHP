<?php

namespace Zeus\ServerService\Shared\Networking;


class SocketServer
{
    protected $socket;

    protected $config;

    public function __construct($config)
    {
        $this->config = $config;
    }

    public function createServer()
    {
        $uri = 'tcp://' . $this->config->getListenAddress() . ':' . $this->config->getListenPort();

        $this->socket = @stream_socket_server($uri, $errno, $errstr, STREAM_SERVER_BIND|STREAM_SERVER_LISTEN);
        if (false === $this->socket) {
            throw new \RuntimeException("Could not bind to $uri: $errstr", $errno);
        }

        stream_set_blocking($this->socket, 0);
    }

    public function listen($timeout)
    {
        $newSocket = @stream_socket_accept($this->socket, $timeout);
        if ($newSocket) {
            stream_set_blocking($newSocket, false);
            return new SocketConnection($newSocket);
        }
    }

    public function stop()
    {
        stream_socket_shutdown($this->socket, STREAM_SHUT_RDWR);
        fclose($this->socket);
        $this->socket = null;
    }
}