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

        $this->socket = socket_create(AF_INET, SOCK_STREAM, 0);
        socket_set_option($this->socket, SOL_SOCKET, SO_REUSEADDR, 1);
        //socket_set_option($this->socket, SOL_SOCKET, TCP_NODELAY, 1);
        socket_bind($this->socket, $this->config->getListenAddress(), $this->config->getListenPort());
        socket_listen($this->socket);

        //@stream_socket_server($uri, $errno, $errstr, STREAM_SERVER_BIND|STREAM_SERVER_LISTEN);
//        if (false === $this->socket) {
//            throw new \RuntimeException("Could not bind to $uri: $errstr", $errno);
//        }

        //stream_set_blocking($this->socket, 0);

        socket_set_nonblock($this->socket);
    }

    public function listen($timeout)
    {
        $newSocket = @socket_accept($this->socket);
        if ($newSocket) {
            return new SocketConnection($newSocket);
        } else {
            usleep(1000);
        }
    }
}