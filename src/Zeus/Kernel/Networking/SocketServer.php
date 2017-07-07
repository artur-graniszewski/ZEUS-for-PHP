<?php

namespace Zeus\Kernel\Networking;
use Zeus\Kernel\Networking\Stream\SocketStream;

/**
 * Class SocketServer
 * @internal
 */
final class SocketServer
{
    /** @var resource */
    protected $socket;

    /** @var int */
    protected $port = -1;

    /** @var string */
    protected $host;

    /** @var int */
    protected $backlog = 5;

    /** @var bool */
    protected $reuseAddress = false;

    /**
     * SocketServer constructor.
     * @param int $port
     * @param int $backlog
     * @param string|null $host
     */
    public function __construct(int $port = -1, int $backlog = null, string $host = null)
    {
        $this->host = $host;

        if ($backlog) {
            $this->backlog = $backlog;
        }

        if ($port >= 0) {
            $this->port = $port;
            $this->createServer();
        }
    }

    /**
     * @param bool $reuse
     * @return $this
     */
    public function setReuseAddress(bool $reuse)
    {
        $this->reuseAddress = $reuse;

        return $this;
    }

    /**
     * @return bool
     */
    public function getReuseAddress()
    {
        return $this->reuseAddress;
    }

    /**
     * @param string $host
     * @param int $backlog
     * @param int $port
     * @return $this
     */
    public function bind(string $host, int $backlog = null, int $port = -1)
    {
        if ($this->isBound()) {
            throw new \LogicException("Server already bound");
        }

        $this->host = $host;
        if ($backlog) {
            $this->backlog = $backlog;
        }

        if ($port >= 0) {
            $this->port = $port;
        } else if ($this->port < 0) {
            throw new \LogicException("Can't bind to $host: no port specified");
        }

        $this->createServer();

        return $this;
    }

    /**
     * @return $this
     */
    protected function createServer()
    {
        $opts = [
            'socket' => [
                'backlog' => $this->backlog,
                'so_reuseport' => $this->getReuseAddress(),
            ],
        ];

        $context = stream_context_create($opts);

        if (!$this->host) {
            $this->host = '0.0.0.0';
        }

        $uri = 'tcp://' . $this->host . ':' . $this->port;

        $this->socket = @stream_socket_server($uri, $errno, $errstr, STREAM_SERVER_BIND|STREAM_SERVER_LISTEN, $context);
        if (false === $this->socket) {
            throw new \RuntimeException("Could not bind to $uri: $errstr", $errno);
        }

        stream_set_blocking($this->socket, 0);

        if ($this->port === 0) {
            $socketName = stream_socket_get_name($this->socket, false);
            $parts = explode(":", $socketName);

            end($parts);

            $this->port = (int) current($parts);
        }

        return $this;
    }

    /**
     * @param int $timeout
     * @return null|SocketStream
     */
    public function accept(int $timeout)
    {
        $newSocket = @stream_socket_accept($this->socket, $timeout, $peerName);
        if (!$newSocket) {
            return null;
        }

        stream_set_blocking($newSocket, false);

        if (function_exists('stream_set_chunk_size')) {
            //stream_set_chunk_size($newSocket, 1);
        }

        if (function_exists('stream_set_read_buffer')) {
            //stream_set_read_buffer($newSocket, 0);
        }

        if (function_exists('stream_set_write_buffer')) {
            //stream_set_write_buffer($newSocket, 0);
        }

        $connection = new SocketStream($newSocket, $peerName);

        return $connection;
    }

    /**
     * @return $this
     */
    public function close()
    {
        if (!$this->socket) {
            throw new \LogicException("Server already stopped");
        }
        @stream_socket_shutdown($this->socket, STREAM_SHUT_RDWR);
        fclose($this->socket);
        $this->socket = null;

        return $this;
    }

    /**
     * @return bool
     */
    public function isBound()
    {
        return is_resource($this->socket);
    }

    /**
     * @return int
     */
    public function getLocalPort()
    {
        return $this->port;
    }

    /**
     * @return null|string
     */
    public function getLocalSocketAddress()
    {
        return $this->host;
    }
}