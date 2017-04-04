<?php

namespace Zeus\Kernel\IpcServer\Adapter;

/**
 * Interface IpcAdapterInterface
 * @package Zeus\Kernel\IpcServer\Adapter
 * @internal
 */
interface IpcAdapterInterface
{
    /**
     * Creates IPC object.
     *
     * @param string $namespace
     * @param mixed[] $config
     */
    public function __construct($namespace, array $config);

    /**
     * Sends a message to the queue.
     *
     * @return $this
     */
    public function send($message);

    /**
     * Receives a message from the queue.
     *
     * @param bool $success
     * @return mixed Received message.
     */
    public function receive(& $success = false);

    /**
     * Receives all messages from the queue.
     *
     * @return mixed Received messages.
     */
    public function receiveAll();

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
    public static function isSupported();

    /**
     * @param int $channelNumber
     * @return $this
     */
    public function useChannelNumber($channelNumber);
}