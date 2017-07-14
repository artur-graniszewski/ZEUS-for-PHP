<?php

namespace Zeus\Kernel\IpcServer\Adapter;

/**
 * Interface IpcAdapterInterface
 * @package Zeus\Kernel\IpcServer\Adapter
 * @internal
 */
interface IpcAdapterInterface
{
    const CLIENT_ENDPOINT = 1;

    const SERVER_CHANNEL = 0;

    /**
     * Creates IPC object.
     *
     * @param string $namespace
     * @param mixed[] $config
     */
    public function __construct($namespace, array $config);

    /**
     * Establishes inter-process communication.
     *
     * @return $this
     */
    public function connect();

    /**
     * Checks if connection is established.
     *
     * @return bool
     */
    public function isConnected();

    /**
     * Sends a message to the queue.
     *
     * @param int $channelNumber
     * @param mixed $message
     * @return $this
     */
    public function send(int $channelNumber, $message);

    /**
     * Receives a message from the queue.
     *
     * @param int $channelNumber
     * @param bool $success
     * @return mixed Received message.
     */
    public function receive(int $channelNumber, & $success = false);

    /**
     * Receives all messages from the queue.
     *
     * @param int $channelNumber
     * @return mixed Received messages.
     */
    public function receiveAll(int $channelNumber);

    /**
     * Destroys this IPC object.
     *
     * @param int $channelNumber
     * @return $this
     */
    public function disconnect($channelNumber = -1);

    /**
     * @return bool
     */
    public function isSupported();

    /**
     * @param int $channelNumber
     * @return $this
     */
    public function checkChannelAvailability(int $channelNumber);
}