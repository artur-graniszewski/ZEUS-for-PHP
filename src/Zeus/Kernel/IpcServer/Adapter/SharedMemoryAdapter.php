<?php

namespace Zeus\Kernel\IpcServer\Adapter;

use Zeus\Kernel\IpcServer\MessageQueueCapacityInterface;
use Zeus\Kernel\IpcServer\MessageSizeLimitInterface;
use Zeus\Kernel\IpcServer\NamedLocalConnectionInterface;

/**
 * Handles Inter Process Communication using APCu functionality.
 * @internal
 */
final class SharedMemoryAdapter implements
    IpcAdapterInterface,
    NamedLocalConnectionInterface,
    MessageQueueCapacityInterface,
    MessageSizeLimitInterface
{
    const READ_INDEX = 1;
    const WRITE_INDEX = 2;
    const MAX_QUEUE_SIZE = 65536;
    const MAX_MEMORY_SIZE = 33554432;

    /** @var string */
    protected $namespace;

    /** @var mixed[] */
    protected $config;

    /** @var int */
    protected $channelNumber = 0;

    /** @var bool[] */
    protected $activeChannels = [0 => true, 1 => true];

    protected $ipc = [0 => null, 1 => null];

    protected $semaphores = [];

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
            $this->semaphores[0] = sem_get($key, 1);
            $key = crc32(sha1($namespace . '_1'));
            $this->ipc[1] = shm_attach($key, 1024 * 1024 * 32);
            $this->semaphores[1] = sem_get($key, 1);
            foreach ($this->ipc as $ipc) {
                @shm_put_var($ipc, static::READ_INDEX, 3);
                if (!shm_has_var($ipc, static::READ_INDEX)) {
                    throw new \RuntimeException("Shared memory segment is unavailable");
                }
                @shm_put_var($ipc, static::WRITE_INDEX, 3);
                if (!shm_has_var($ipc, static::WRITE_INDEX)) {
                    throw new \RuntimeException("Shared memory segment is unavailable");
                }
            }
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

        sem_acquire($this->semaphores[$channelNumber]);
        $index = shm_get_var($this->ipc[$channelNumber], static::WRITE_INDEX);
        $exists = shm_has_var($this->ipc[$channelNumber], $index);
        if ($exists) {
            sem_release($this->semaphores[$channelNumber]);
            throw new \RuntimeException(sprintf('Message queue on channel %d', $channelNumber));
        }

        function_exists('error_clear_last') ? error_clear_last() : @trigger_error("", E_USER_NOTICE);
        $success = @shm_put_var($this->ipc[$channelNumber], $index, $message);

        if (!$success) {
            sem_release($this->semaphores[$channelNumber]);
            $error = error_get_last();
            throw new \RuntimeException(sprintf('Error occurred when sending message to channel %d: %s', $channelNumber, $error['message']));
        }

        $index++;
        if ($this->getMessageQueueCapacity() < $index) {
            $index = 3;
        }

        shm_put_var($this->ipc[$channelNumber], static::WRITE_INDEX, $index);
        sem_release($this->semaphores[$channelNumber]);

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

        sem_acquire($this->semaphores[$channelNumber]);
        $readIndex = shm_get_var($this->ipc[$channelNumber], static::READ_INDEX);
        $success = shm_has_var($this->ipc[$channelNumber], $readIndex);

        $result = null;
        if ($success) {
            $result = shm_get_var($this->ipc[$channelNumber], $readIndex);
            shm_remove_var($this->ipc[$channelNumber], $readIndex);
            $readIndex++;
        }

        if ($this->getMessageQueueCapacity() < $readIndex) {
            $readIndex = 3;
        }

        shm_put_var($this->ipc[$channelNumber], static::READ_INDEX, $readIndex);
        sem_release($this->semaphores[$channelNumber]);

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
        while ($result = $this->receive($success) && $success) {
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
            shm_detach($this->ipc[$channelNumber]);
            unset($this->ipc[$channelNumber]);
            $this->activeChannels[$channelNumber] = false;

            return $this;
        }

        foreach (array_keys($this->ipc) as $channelNumber) {
            shm_remove($this->ipc[$channelNumber]);
            shm_detach($this->ipc[$channelNumber]);
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
        return (
            !defined('HHVM_VERSION') // @todo: troubleshoot HHVM issues
            &&
            function_exists('shm_get_var')
            &&
            function_exists('shm_put_var')
            &&
            function_exists('shm_remove_var')
            &&
            function_exists('shm_remove')
            &&
            function_exists('sem_get')
            &&
            function_exists('shm_attach')
            &&
            function_exists('shm_detach')
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

    /**
     * @return int
     */
    public function getMessageQueueCapacity()
    {
        return static::MAX_QUEUE_SIZE;
    }

    /**
     * @return int
     */
    public function getMessageSizeLimit()
    {
        return static::MAX_MEMORY_SIZE;
    }
}