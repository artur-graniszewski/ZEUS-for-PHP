<?php

namespace Zeus\Kernel\IpcServer\Adapter;

use Zeus\Kernel\IpcServer\Adapter\Helper\MessagePackager;
use Zeus\Kernel\IpcServer\MessageSizeLimitInterface;
use Zeus\Kernel\IpcServer\NamedLocalConnectionInterface;
use Zeus\Networking\Stream\FlushableConnectionInterface;
use Zeus\Networking\Stream\PipeStream;

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

    /** @var PipeStream[] sockets */
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

        $ipc[0] = fopen($fileName1, "r+"); // ensures at least one writer (us) so will be non-blocking
        $ipc[1] = fopen($fileName2, "r+"); // ensures at least one writer (us) so will be non-blocking
        stream_set_blocking($ipc[0], false); // prevent fread / fwrite blocking
        stream_set_blocking($ipc[1], false); // prevent fread / fwrite blocking
        $this->ipc[0] = new PipeStream($ipc[0]);
        $this->ipc[1] = new PipeStream($ipc[1]);
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

        $channelNumber = $channelNumber == 0 ? 1 : 0;

        $this->checkChannelAvailability($channelNumber);

        $message = $this->packMessage($message);
        if (strlen($message) + 1 > $this->getMessageSizeLimit()) {
            throw new \RuntimeException(sprintf("Message length exceeds max packet size of %d bytes",  $this->getMessageSizeLimit()));
        }

        $this->ipc[$channelNumber]->write($message . "\0");
        if ($this->ipc[$channelNumber] instanceof FlushableConnectionInterface) {
            $this->ipc[$channelNumber]->flush();
        }

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

        $this->checkChannelAvailability($channelNumber);

        if (!$this->ipc[$channelNumber]->select(0)) {

            return null;
        }

        $message = $this->ipc[$channelNumber]->read("\0");

        if (is_string($message) && $message !== '') {
            $message = $this->unpackMessage($message);
            $success = true;
            return $message;
        }

        return null;
    }

    /**
     * Receives all messages from the queue.
     *
     * @return mixed[] Received messages.
     */
    public function receiveAll()
    {
        $channelNumber = $this->channelNumber;

        $this->checkChannelAvailability($channelNumber);

        if (!isset($this->ipc[$channelNumber])) {
            throw new \RuntimeException('Channel number ' . $channelNumber . ' is already closed');
        }

        if (!$this->ipc[$channelNumber]->select(1000)) {
            return [];
        }

        $messages = [];

        for (;;) {
            $message = $this->receive($success);
            if (!$success) {

                break;
            }

            $messages[] = $message;
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

            $this->ipc[$channelNumber]->close();
            $this->ipc[$channelNumber] = null;
            $this->activeChannels[$channelNumber] = false;

            return $this;
        }

        foreach ($this->ipc as $channelNumber => $stream) {
            if (!$stream) {
                continue;
            }
            $stream->close();

            $fileName = $this->getFilename($channelNumber);
            unlink($fileName);
            $this->ipc[$channelNumber] = null;
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
        if (!$this->isSupported()) {
            throw new \RuntimeException("Adapter not supported by the PHP configuration");
        }

        if (!static::$maxPipeCapacity) {
            $fileName = $this->getFilename(2) . '-' . getmypid();
            posix_mkfifo($fileName, 0600);

            $ipc = fopen($fileName, "r+"); // ensures at least one writer so it will be non-blocking
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
            @unlink($fileName);

            static::$maxPipeCapacity = $size;
        }

        return static::$maxPipeCapacity;
    }
}