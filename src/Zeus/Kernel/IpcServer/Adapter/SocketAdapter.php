<?php

namespace Zeus\Kernel\IpcServer\Adapter;

use Zeus\Kernel\IpcServer\Adapter\Helper\MessagePackager;
use Zeus\Kernel\IpcServer\AnonymousLocalConnectionInterface;
use Zeus\Kernel\IpcServer\MessageSizeLimitInterface;

/**
 * Handles Inter Process Communication using sockets functionality.
 *
 * @internal
 */
final class SocketAdapter implements
    IpcAdapterInterface,
    AnonymousLocalConnectionInterface,
    MessageSizeLimitInterface
{
    use MessagePackager;

    const MAX_MESSAGE_SIZE = 131072;

    /** @var resource[] sockets */
    protected $ipc = [];

    /** @var string */
    protected $namespace;

    /** @var int */
    protected $channelNumber = 0;

    /** @var mixed[] */
    protected $config;

    /** @var bool[] */
    protected $activeChannels = [0 => true, 1 => true];

    /** @var bool */
    protected $connected;

    /**
     * Creates IPC object.
     *
     * @param string $namespace
     * @param mixed[] $config
     */
    public function __construct($namespace, array $config)
    {
        $this->namespace = $namespace;
        $this->config = $config;
    }

    /**
     * @return bool
     */
    public function isConnected()
    {
        return $this->connected;
    }

    /**
     * @return $this
     */
    public function connect()
    {
        if ($this->connected) {
            throw new \LogicException("Connection already established");
        }

        if (!$this->isSupported()) {
            throw new \RuntimeException("Adapter not supported by the PHP configuration");
        }

        $domain = strtoupper(substr(PHP_OS, 0, 3) == 'WIN' ? AF_INET : AF_UNIX);

        if (!socket_create_pair($domain, SOCK_SEQPACKET, 0, $this->ipc)) {
            $errorCode = socket_last_error();
            throw new \RuntimeException("Could not create IPC socket: " . socket_strerror($errorCode), $errorCode);
        }

        socket_set_nonblock($this->ipc[0]);
        socket_set_nonblock($this->ipc[1]);

        $this->connected = true;

        return $this;
    }

    /**
     * @param int $channelNumber
     * @return $this
     */
    public function useChannelNumber($channelNumber)
    {
        $this->checkChannelAvailability($channelNumber);

        $this->channelNumber = $channelNumber;

        return $this;
    }

    /**
     * Sends a message to the queue.
     *
     * @param string $message
     * @return $this
     */
    public function send($message)
    {
        $this->checkChannelAvailability($this->channelNumber);
        $message = $this->packMessage($message);

        socket_set_block($this->ipc[$this->channelNumber]);
        socket_write($this->ipc[$this->channelNumber], $message . "\n", strlen($message) + 1);
        socket_set_nonblock($this->ipc[$this->channelNumber]);

        return $this;
    }

    /**
     * Receives a message from the queue.
     *
     * @param bool $success
     * @return mixed Received message.
     */
    public function receive(& $success = false)
    {
        $message = '';
        $success = false;
        $this->checkChannelAvailability($this->channelNumber);

        $readSocket = [$this->ipc[$this->channelNumber]];
        $writeSocket = $except = [];

        $value = @socket_select($readSocket, $writeSocket, $except, 0, 10);

        if ($value === false) {
            throw new \RuntimeException(sprintf('Error %d occurred when receiving data from channel number %d', socket_last_error($this->ipc[$this->channelNumber]), $this->channelNumber));
        }

        if ($value === 0) {
            return null;
        }

        defined('HHVM_VERSION') ?
            // HHVM...
            $message = stream_get_line($readSocket[0], static::MAX_MESSAGE_SIZE)
            :
            //socket_recv($readSocket[0], $message, static::MAX_MESSAGE_SIZE, MSG_DONTWAIT);
            $message = socket_read($readSocket[0], static::MAX_MESSAGE_SIZE);

        if (is_string($message) && $message !== "") {
            $success = true;
            return $this->unpackMessage($message);
        }
    }

    /**
     * Receives all messages from the queue.
     *
     * @return mixed[] Received messages.
     */
    public function receiveAll()
    {
        $this->checkChannelAvailability($this->channelNumber);

        $readSocket = [$this->ipc[$this->channelNumber]];
        $writeSocket = $except = [];
        $messages = [];

        if (@socket_select($readSocket, $writeSocket, $except, 1)) {
            for (;;) {
                $message = $this->receive($success);
                if (!$success) {

                    break;
                }

                $messages[] = $message;
            }
        }

        return $messages;
    }

    /**
     * @param int $channelNumber
     * @return $this
     */
    public function disconnect($channelNumber = -1)
    {
        if ($channelNumber !== -1) {
            $this->checkChannelAvailability($channelNumber);
            $socket = $this->ipc[$channelNumber];
            socket_shutdown($socket, 2);
            socket_close($socket);
            unset($this->ipc[$channelNumber]);
            $this->activeChannels[$channelNumber] = false;
            return $this;
        }

        foreach ($this->ipc as $channelNumber => $socket) {
            socket_shutdown($socket, 2);
            socket_close($socket);
            unset($this->ipc[$channelNumber]);
            $this->activeChannels[$channelNumber] = false;
        }

        return $this;
    }

    /**
     * @param int $channelNumber
     */
    protected function checkChannelAvailability($channelNumber)
    {
        if (!$this->connected) {
            throw new \LogicException("Connection is not established");
        }
        if (!isset($this->activeChannels[$channelNumber]) || $this->activeChannels[$channelNumber] !== true) {
            throw new \LogicException(sprintf('Channel number %d is unavailable', $channelNumber));
        }
    }

    /**
     * @return bool
     */
    public function isSupported()
    {
        return function_exists('socket_create_pair');
    }

    /**
     * @return int
     */
    public function getMessageSizeLimit()
    {
        return static::MAX_MESSAGE_SIZE;
    }
}