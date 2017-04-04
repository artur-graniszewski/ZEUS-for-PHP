<?php

namespace Zeus\Kernel\IpcServer\Adapter;

use Zeus\Kernel\IpcServer\NamedLocalConnectionInterface;

/**
 * Handles Inter Process Communication using APCu functionality.
 * @internal
 */
final class SharedMemoryAdapter implements
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

    protected $ipc = [0 => null, 1 => null];

    protected $sem = [];

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

        if (static::isSupported()) {
            $key = crc32(sha1($namespace . '_0'));
            $this->ipc[0] = shm_attach($key, 1024 * 1024 * 32);
            $this->sem[0] = sem_get($key, 1);
            $key = crc32(sha1($namespace . '_1'));
            $this->ipc[1] = shm_attach($key, 1024 * 1024 * 32);
            $this->sem[1] = sem_get($key, 1);
            shm_put_var($this->ipc[0], 1, 3);
            shm_put_var($this->ipc[0], 2, 3);
            shm_put_var($this->ipc[0], 1, 3);
            shm_put_var($this->ipc[1], 2, 3);
        }
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

        sem_acquire($this->sem[$channelNumber]);
        $index = shm_get_var($this->ipc[$channelNumber], 2);
        $success = shm_put_var($this->ipc[$channelNumber], $index, $message);

        if (!$success) {
            sem_release($this->sem[$channelNumber]);
            throw new \RuntimeException(sprintf('Error occurred when sending message to channel %d', $channelNumber));
        }

        $index++;
        if (65535 < $index) {
            $index = 3;
        }

        shm_put_var($this->ipc[$channelNumber], 2, $index);
        sem_release($this->sem[$channelNumber]);

        return $this;
    }

    /**
     * Receives a message from the queue.
     *
     * @return mixed Received message.
     */
    public function receive()
    {
        $channelNumber = $this->channelNumber;

        $this->checkChannelAvailability($channelNumber);

        sem_acquire($this->sem[$channelNumber]);
        $success = shm_has_var($this->ipc[$channelNumber], 1);

        $readIndex = $success ? (int) shm_get_var($this->ipc[$channelNumber], 1) : 3;
        $success = shm_has_var($this->ipc[$channelNumber], $readIndex);

        $result = null;
        if ($success) {
            $result = shm_get_var($this->ipc[$channelNumber], $readIndex);
            shm_remove_var($this->ipc[$channelNumber], $readIndex);
        }

        $readIndex++;

        if (65535 < $readIndex) {
            $readIndex = 3;
        }

        shm_put_var($this->ipc[$channelNumber], 1, $readIndex);
        sem_release($this->sem[$channelNumber]);

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
        while ($result = $this->receive()) {
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
    public function disconnect($channelNumber = -1)
    {
        if ($channelNumber !== -1) {
            $this->checkChannelAvailability($channelNumber);

            shm_remove($this->ipc[$channelNumber]);
            unset($this->ipc[$channelNumber]);
            $this->activeChannels[$channelNumber] = false;

            return $this;
        }

        foreach (array_keys($this->ipc) as $channelNumber) {
            shm_remove($this->ipc[$channelNumber]);
            unset($this->ipc[$channelNumber]);
        }

        $this->activeChannels = [0 => false, 1 => false];

        return $this;
    }

    /**
     * @param int $channelNumber
     */
    protected function checkChannelAvailability($channelNumber)
    {
        if (!isset($this->activeChannels[$channelNumber]) || $this->activeChannels[$channelNumber] !== true) {
            throw new \LogicException(sprintf('Channel number %d is unavailable', $channelNumber));
        }
    }

    /**
     * @return bool
     */
    public static function isSupported()
    {
        return false;
        return (
            function_exists('shm_get_var')
            &&
            function_exists('shm_put_var')
            &&
            function_exists('shm_remove_var')
            &&
            function_exists('shm_remove')
            &&
            function_exists('shm_get')
            &&
            function_exists('shm_attach')
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