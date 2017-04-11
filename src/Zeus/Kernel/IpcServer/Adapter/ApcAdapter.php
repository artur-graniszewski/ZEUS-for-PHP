<?php

namespace Zeus\Kernel\IpcServer\Adapter;

use Zeus\Kernel\IpcServer\NamedLocalConnectionInterface;

/**
 * Handles Inter Process Communication using APCu functionality.
 * @internal
 */
final class ApcAdapter implements
    IpcAdapterInterface,
    NamedLocalConnectionInterface
{
    /** @var string */
    protected $namespace;

    /** @var mixed[] */
    protected $config;

    /** @var int */
    protected $channelNumber = 0;

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
     * return $this
     */
    public function connect()
    {
        if ($this->connected) {
            throw new \LogicException("Connection already established");
        }

        if (!$this->isSupported()) {
            throw new \RuntimeException("Adapter not supported by the PHP configuration");
        }

        apcu_store($this->namespace . '_readindex_0', 0, 0);
        apcu_store($this->namespace . '_writeindex_0', 0, 0);
        apcu_store($this->namespace . '_readindex_1', 0, 0);
        apcu_store($this->namespace . '_writeindex_1', 0, 0);

        $this->connected = true;

        return $this;
    }

    /**
     * @return bool
     */
    public function isConnected()
    {
        return $this->connected;
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

        $channelNumber == 0 ?
            $channelNumber = 1
            :
            $channelNumber = 0;

        $this->checkChannelAvailability($channelNumber);

        $index = apcu_fetch($this->namespace . '_writeindex_' . $channelNumber);
        $success = apcu_store($this->namespace . '_data_' . $channelNumber . '_' . $index, $message, 0);

        if (!$success) {
            throw new \RuntimeException(sprintf('Error occurred when sending message to channel %d', $channelNumber));
        }

        if (65535 < apcu_inc($this->namespace . '_writeindex_' . $channelNumber)) {
            apcu_store($this->namespace . '_writeindex_' . $channelNumber, 0, 0);
        }

        return $this;
    }

    /**
     * Receives a message from the queue.
     *
     * @return mixed Received message.
     */
    public function receive(& $success = false)
    {
        $success = false;
        $channelNumber = $this->channelNumber;

        $this->checkChannelAvailability($channelNumber);

        $readIndex = apcu_fetch($this->namespace . '_readindex_' . $channelNumber);
        $result = apcu_fetch($this->namespace . '_data_' . $channelNumber . '_' . $readIndex, $success);
        apcu_delete($this->namespace . '_data_' . $channelNumber . '_' . $readIndex);

        if ($success && 65535 < apcu_inc($this->namespace . '_readindex_' . $channelNumber)) {
            apcu_store($this->namespace . '_readindex_' . $channelNumber, 0, 0);
        }

        if (!$success) {
            usleep(1000);
        }

        return $result;
    }

    /**
     * Receives all messages from the queue.
     *
     * @return mixed[] Received messages.
     */
    public function receiveAll()
    {
        $results = [];
        $success = true;
        while (($result = $this->receive($success)) && $success) {
            $results[] = $result;
        }

        return $results;
    }

    /**
     * Destroys this IPC object.
     *
     * @param int $channelNumber
     * @return $this
     */
    public function disconnect($channelNumber = 0)
    {
        if ($channelNumber !== -1) {
            $this->checkChannelAvailability($channelNumber);

            apcu_delete($this->namespace . '_writeindex_' . $channelNumber);
            $this->activeChannels[$channelNumber] = false;

            return $this;
        }

        foreach (range(0, 1) as $channelNumber) {
            apcu_delete($this->namespace . '_writeindex_' . $channelNumber);
        }

        $this->activeChannels = [0 => false, 1 => false];

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
        return (
            extension_loaded('apcu')
            &&
            false !== @apcu_cache_info()
            &&
            function_exists('apcu_store')
            &&
            function_exists('apcu_fetch')
        );
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
}