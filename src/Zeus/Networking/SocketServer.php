<?php

namespace Zeus\Networking;
use Zeus\Networking\Exception\SocketException;
use Zeus\Networking\Exception\SocketTimeoutException;
use Zeus\Networking\Stream\SocketStream;
use Zeus\Util\UnitConverter;

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

    /** @var bool */
    protected $isClosed = false;

    /** @var bool */
    protected $isBound = false;

    protected $soTimeout = 0;

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

        $this->isBound = true;

        return $this;
    }

    /**
     * @return null|SocketStream
     */
    public function accept() : SocketStream
    {
        $timeout = UnitConverter::convertMillisecondsToSeconds($this->getSoTimeout());

        $newSocket = @stream_socket_accept($this->socket, $timeout, $peerName);
        if (!$newSocket) {
            throw new SocketTimeoutException('Socket timed out');
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
     * @param int $option
     * @param mixed $value
     * @return $this
     */
    public function setOption(int $option, mixed $value)
    {
        if (!$this->socket) {
            throw new SocketException("Socket must be bound first");
        }

        $socket = socket_import_stream($this->socket);
        socket_set_option($socket, SOL_SOCKET, $option, $value);

        return $this;
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
    public function getLocalSocketAddress() : string
    {
        return $this->host;
    }

    /**
     * @return bool
     */
    public function isIsClosed() : bool
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
}