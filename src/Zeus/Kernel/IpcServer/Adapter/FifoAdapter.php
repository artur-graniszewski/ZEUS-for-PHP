<?php

namespace Zeus\Kernel\IpcServer\Adapter;

use Zeus\Kernel\IpcServer\Adapter\Helper\MessagePackager;
use Zeus\Kernel\IpcServer\MessageSizeLimitInterface;
use Zeus\Kernel\IpcServer\NamedLocalConnectionInterface;

/**
 * Class FifoAdapter
 * @package Zeus\Kernel\IpcServer\Adapter
 * @internal
 */
final class FifoAdapter implements
    IpcAdapterInterface,
    NamedLocalConnectionInterface,
    MessageSizeLimitInterface
{
    use MessagePackager;

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

    protected static $maxPipeCapacity = null;

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

        $fileName1 = $this->getFilename(0);
        $fileName2 = $this->getFilename(1);
        posix_mkfifo($fileName1, 0600);
        posix_mkfifo($fileName2, 0600);

        $this->ipc[0] = fopen($fileName1, "r+"); // ensures at least one writer (us) so will be non-blocking
        $this->ipc[1] = fopen($fileName2, "r+"); // ensures at least one writer (us) so will be non-blocking
        stream_set_blocking($this->ipc[0], false); // prevent fread / fwrite blocking
        stream_set_blocking($this->ipc[1], false); // prevent fread / fwrite blocking
        $this->getMessageSizeLimit();

        $this->connected = true;

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

    protected function getFilename($channelNumber)
    {
        return sprintf("%s/%s.%d", getcwd(), $this->namespace, $channelNumber);
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
        $channelNumber = $this->channelNumber;
        $this->checkChannelAvailability($channelNumber);
        $message = $this->packMessage($message);

        if (strlen($message) + 1 > $this->getMessageSizeLimit()) {
            throw new \RuntimeException(sprintf("Message length exceeds max packet size of %d bytes",  $this->getMessageSizeLimit()));
        }

        fwrite($this->ipc[$channelNumber], $message . "\0", strlen($message) + 1);

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
        $success = false;
        $channelNumber = $this->channelNumber;

        $channelNumber == 0 ?
            $channelNumber = 1
            :
            $channelNumber = 0;

        $this->checkChannelAvailability($channelNumber);

        $readSocket = [$this->ipc[$channelNumber]];
        $writeSocket = $except = [];

        if (!@stream_select($readSocket, $writeSocket, $except, 0, 10)) {

            return null;
        }

        //defined('HHVM_VERSION') ?
            // HHVM...
            $message = stream_get_line($readSocket[0], $this->getMessageSizeLimit()  , "\0");
            //:
            //socket_recv($readSocket[0], $message, static::MAX_MESSAGE_SIZE, MSG_DONTWAIT);
            //$message = socket_read($readSocket[0], $this->getMessageSizeLimit());

        if (is_string($message) && $message !== "") {
            $message = $this->unpackMessage($message);
            $success = true;
            return $message;
        }
    }

    /**
     * Receives all messages from the queue.
     *
     * @return mixed[] Received messages.
     */
    public function receiveAll()
    {
        $channelNumber = $this->channelNumber;

        $channelNumber == 0 ?
            $channelNumber = 1
            :
            $channelNumber = 0;

        $this->checkChannelAvailability($channelNumber);

        if (!isset($this->ipc[$channelNumber])) {
            throw new \RuntimeException('Channel number ' . $channelNumber . ' is already closed');
        }
        $readSocket = [$this->ipc[$channelNumber]];
        $writeSocket = $except = [];
        $messages = [];

        if (@stream_select($readSocket, $writeSocket, $except, 1)) {
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

            fclose($this->ipc[$channelNumber]);
            $this->activeChannels[$channelNumber] = false;

            return $this;
        }

        foreach ($this->ipc as $channelNumber => $stream) {
            fclose($this->ipc[$channelNumber]);
            unlink($this->getFilename($channelNumber));
            unset($this->ipc[$channelNumber]);
        }

        $this->activeChannels = [0 => false, 1 => false];

        return $this;
    }

    /**
     * @return bool
     */
    public function isSupported()
    {
        return function_exists('posix_mkfifo');
    }

    /**
     * @return int
     */
    public function getMessageSizeLimit()
    {
        if (!static::$maxPipeCapacity) {
            $fileName = $this->getFilename(2);
            posix_mkfifo($fileName, 0600);

            $ipc = fopen($fileName, "r+"); // ensures at least one writer (us) so will be non-blocking
            stream_set_blocking($ipc, false);

            $wrote = 1;
            $size = 1;
            $message = str_repeat('a', 524288);
            while ($size < 524288 && $wrote > 0) {
                if (fwrite($ipc, $message, $size) !== $size) {
                    $size = $size >> 1;
                    break;
                }

                fgets($ipc);
                $size = $size << 1;
            }

            fclose($ipc);
            unlink($fileName);

            static::$maxPipeCapacity = $size;
        }

        return static::$maxPipeCapacity;
    }
}