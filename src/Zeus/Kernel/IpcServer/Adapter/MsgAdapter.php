<?php

namespace Zeus\Kernel\IpcServer\Adapter;

use Zeus\Kernel\IpcServer\Adapter\Helper\MessagePackager;
use Zeus\Kernel\IpcServer\MessageQueueCapacityInterface;
use Zeus\Kernel\IpcServer\MessageSizeLimitInterface;
use Zeus\Kernel\IpcServer\NamedLocalConnectionInterface;

/**
 * Handles Inter Process Communication using SystemV functionality.
 *
 * @internal
 */
final class MsgAdapter implements
    IpcAdapterInterface,
    NamedLocalConnectionInterface,
    MessageSizeLimitInterface,
    MessageQueueCapacityInterface
{
    use MessagePackager;

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

    /** @var int[] */
    protected $queueInfo;

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

        $id1 = $this->getQueueId();
        $this->ipc[0] = msg_get_queue($id1, 0600);
        msg_set_queue($this->ipc[0], ['msg_qbytes' => $this->getMessageSizeLimit()]);
        $id2 = $this->getQueueId();
        $this->ipc[1] = msg_get_queue($id2, 0600);
        msg_set_queue($this->ipc[0], ['msg_qbytes' => $this->getMessageSizeLimit()]);

        if (!$id1 || !$id2) {
            // something went wrong
            throw new \RuntimeException("Failed to find a queue for IPC");
        }

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

    /**
     * @return int
     */
    protected function getQueueId()
    {
        $queueId = 0;
        $info = $this->getQueueInfo();
        $maxQueueId = $info['queues_max'];

        while ($queueId < $maxQueueId) {
            if (!msg_queue_exists($queueId)) {
                return $queueId;
            }

            $queueId++;
        }

        throw new \RuntimeException('No available queue was found');
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
        $message = $this->packMessage($message);

        if (strlen($message) + 1 > $this->getMessageSizeLimit()) {
            throw new \RuntimeException(sprintf("Message length exceeds max packet size of %d bytes",  $this->getMessageSizeLimit()));
        }

        function_exists('error_clear_last') ? error_clear_last() : @trigger_error("", E_USER_NOTICE);
        if (!@msg_send($this->ipc[$channelNumber], 1, $message, true, true, $errorNumber)) {
            $error = error_get_last();
            throw new \RuntimeException(sprintf('Error %d occurred when sending message to channel %d: %s', $errorNumber, $channelNumber, $error['message']));
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

        $messageType = 1;
        $success = msg_receive($this->ipc[$channelNumber], $messageType, $messageType,  $this->getMessageSizeLimit(), $message, true, MSG_IPC_NOWAIT);

        return $this->unpackMessage($message);
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
            $message = $this->receive($success);

            if (!$success) {
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
            $this->checkChannelAvailability($channelNumber);

            msg_remove_queue($this->ipc[$channelNumber]);
            unset($this->ipc[$channelNumber]);
            $this->activeChannels[$channelNumber] = false;

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
    public function isSupported()
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

    /**
     * @return int
     */
    public function getMessageSizeLimit()
    {
        $info = $this->getQueueInfo();

        return $info['msg_qbytes'];
    }

    /**
     * @return int
     */
    public function getMessageQueueCapacity()
    {
        $info = $this->getQueueInfo();

        return $info['msg_default'];
    }

    /**
     * @return int[]
     */
    protected function getQueueInfo()
    {
        if (!$this->queueInfo) {
            $id = null;
            $queue = $this->ipc[0] ? $this->ipc[0] : ($this->ipc[1] ? $this->ipc[1] : null);

            // detect queue limits...
            $this->queueInfo['msg_default'] = 10;
            $fileName = '/proc/sys/fs/mqueue/msg_default';
            if (file_exists($fileName) && is_readable($fileName)) {
                $this->queueInfo['msg_default'] = (int) file_get_contents($fileName);
            }

            $this->queueInfo['queues_max'] = 256;
            $fileName = '/proc/sys/fs/mqueue/queues_max';
            if (file_exists($fileName) && is_readable($fileName)) {
                $this->queueInfo['queues_max'] = (int) file_get_contents($fileName);
            }

            if (!$queue) {
                $id = $this->getQueueId();
                $queue = msg_get_queue($id, 0600);
            }

            $this->queueInfo = array_merge($this->queueInfo, msg_stat_queue($queue));
            if ($id) {
                msg_remove_queue($queue);
            }
        }

        return $this->queueInfo;
    }
}