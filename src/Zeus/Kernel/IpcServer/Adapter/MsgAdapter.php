<?php

namespace Zeus\Kernel\IpcServer\Adapter;

/**
 * Handles Inter Process Communication using SystemV functionality.
 *
 * @internal
 */
final class MsgAdapter implements IpcAdapterInterface
{
    const MAX_MESSAGE_SIZE = 16384;

    /**
     * Queue links.
     *
     * @var resource[]
     */
    protected $ipc;

    /** @var string */
    protected $namespace;

    /** @var mixed[] */
    protected $config;

    /** @var int */
    protected $channelNumber = 0;

    /** @var bool[] */
    protected $activeChannels = [0 => true, 1 => true];

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

        $id1 = $this->getQueueId();
        $this->ipc[0] = msg_get_queue($id1, 0600);
        $id2 = $this->getQueueId();
        $this->ipc[1] = msg_get_queue($id2, 0600);

        if (!$id1 || !$id2) {
            // something went wrong
            throw new \RuntimeException("Failed to find a queue for IPC");
        }
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
     * @todo: handle situation where all queues are reserved already
     * @return int|bool
     */
    protected function getQueueId()
    {
        $queueId = 0;

        while (msg_queue_exists($queueId)) {
            $queueId++;
        }

        return $queueId;
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

        if (strlen($message) > static::MAX_MESSAGE_SIZE) {
            throw new \RuntimeException("Message lengths exceeds max packet size of " . static::MAX_MESSAGE_SIZE);
        }

        if (!@msg_send($this->ipc[$channelNumber], 1, $message, true, true, $errorNumber)) {
            throw new \RuntimeException(sprintf('Error %d occurred when sending message to channel %d', $errorNumber, $channelNumber));
        }

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

        $messageType = 1;
        msg_receive($this->ipc[$channelNumber], $messageType, $messageType, self::MAX_MESSAGE_SIZE, $message, true, MSG_IPC_NOWAIT);

        return $message;
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

        $messages = [];

        // early elimination
        $stats = msg_stat_queue($this->ipc[$channelNumber]);
        if (!$stats['msg_qnum']) {

            // nothing to read
            return $messages;
        }

        for(;;) {
            $message = $this->receive();

            if (!$message) {
                break;
            }

            $messages[] = $message;
        }

        return $messages;
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
            return $this;
        }

        foreach (array_keys($this->ipc) as $channelNumber) {
            msg_remove_queue($this->ipc[$channelNumber]);
            unset($this->ipc[$channelNumber]);
        }

        $this->activeChannels = [0 => false, 1 => false];

        return $this;
    }

    /**
     * @return bool
     */
    public static function isSupported()
    {
        return function_exists('msg_stat_queue');
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