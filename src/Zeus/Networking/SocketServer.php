<?php

namespace Zeus\Networking;

use Zeus\Networking\Exception\SocketException;
use Zeus\Networking\Exception\SocketTimeoutException;
use Zeus\Networking\Stream\SelectableStreamInterface;
use Zeus\Networking\Stream\SocketStream;
use Zeus\Util\UnitConverter;

/**
 * Class SocketServer
 * @internal
 */
final class SocketServer
{
    /** @var resource */
    protected $resource;

    /** @var int */
    protected $port = -1;

    /** @var string */
    protected $host;

    /** @var int */
    protected $backlog = 5;

    /** @var bool */
    protected $reuseAddress = false;

    /** @var bool */
    protected $isClosed = false;

    /** @var bool */
    protected $isBound = false;

    protected $soTimeout = 0;

    /** @var bool */
    protected $tcpNoDelay;

    /** @var SelectableStreamInterface */
    protected $socketObject;

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
    public function getReuseAddress() : bool
    {
        return $this->reuseAddress;
    }

    /**
     * @return bool
     */
    public function getTcpNoDelay(): bool
    {
        return $this->tcpNoDelay;
    }

    /**
     * @param bool $tcpNoDelay
     * @return $this
     */
    public function setTcpNoDelay(bool $tcpNoDelay)
    {
        $this->tcpNoDelay = $tcpNoDelay;

        return $this;
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
                'tcp_nodelay' => $this->tcpNoDelay,
            ],
        ];

        $context = stream_context_create($opts);

        if (!$this->host) {
            $this->host = '0.0.0.0';
        }

        $uri = 'tcp://' . $this->host . ':' . $this->port;

        $this->resource = @stream_socket_server($uri, $errno, $errstr, STREAM_SERVER_BIND|STREAM_SERVER_LISTEN, $context);
        if (false === $this->resource) {
            throw new SocketException("Could not bind to $uri: $errstr", $errno);
        }

        if ($this->port === 0) {
            $socketName = \stream_socket_get_name($this->resource, false);
            $parts = explode(":", $socketName);

            end($parts);

            $this->port = (int) current($parts);
        }

        $this->isBound = true;

        return $this;
    }

    /**
     * @return null|SocketStream
     */
    public function accept() : SocketStream
    {
        $timeout = UnitConverter::convertMillisecondsToSeconds($this->getSoTimeout());

        $newSocket = @\stream_socket_accept($this->resource, $timeout, $peerName);
        if (!$newSocket) {
            throw new SocketTimeoutException('Socket timed out');
        }

        $connection = new SocketStream($newSocket, $peerName);

        return $connection;
    }

    /**
     * @param int $option
     * @param mixed $value
     * @return $this
     */
    public function setOption(int $option, $value)
    {
        if (!$this->resource) {
            throw new SocketException("Socket must be bound first");
        }

        $socket = socket_import_stream($this->resource);
        socket_set_option($socket, SOL_SOCKET, $option, $value);

        return $this;
    }

    /**
     * @return $this
     */
    public function close()
    {
        if (!$this->resource) {
            throw new \LogicException("Server already stopped");
        }

        stream_set_blocking($this->resource, true);
        @stream_socket_shutdown($this->resource, STREAM_SHUT_RD);
//        fread($this->socket, 4096);
        fclose($this->resource);
        $this->resource = null;
        $this->isClosed = true;

        return $this;
    }

    /**
     * @return bool
     */
    public function isBound() : bool
    {
        return $this->isBound;
    }

    /**
     * @return int
     */
    public function getLocalPort() : int
    {
        return $this->port;
    }

    /**
     * @return null|string
     */
    public function getLocalAddress() : string
    {
        return $this->host . ($this->port ? ':' . $this->port : '');
    }

    /**
     * @return bool
     */
    public function isClosed() : bool
    {
        return $this->isClosed;
    }

    /**
     * @return bool
     */
    public function isIsBound() : bool
    {
        return $this->isBound;
    }

    /**
     * @return int
     */
    public function getSoTimeout() : int
    {
        return $this->soTimeout;
    }

    /**
     * @param int $soTimeout Timeout in milliseconds
     * @return $this
     */
    public function setSoTimeout(int $soTimeout)
    {
        $this->soTimeout = $soTimeout;

        return $this;
    }

    public function getSocket() : SelectableStreamInterface
    {
        if (!$this->socketObject) {
            $this->socketObject = new SocketStream($this->resource);
        }

        return $this->socketObject;
    }
}