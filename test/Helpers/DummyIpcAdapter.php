<?php

namespace ZeusTest\Helpers;

use Zeus\Kernel\IpcServer\Adapter\IpcAdapterInterface;

class DummyIpcAdapter implements IpcAdapterInterface
{
    protected $messages;
    protected $channelNumber = 0;


    /**
     * Creates IPC object.
     *
     * @param string $namespace
     * @param mixed[] $config
     */
    public function __construct($namespace, array $config)
    {

    }

    /**
     * Sends a message to the queue.
     *
     * @return $this
     */
    public function send($message)
    {
        $this->messages[$this->channelNumber][] = $message;

        return $this;
    }

    /**
     * Receives a message from the queue.
     *
     * @return mixed Received message.
     */
    public function receive()
    {
        $result = null;

        $channelNumber = $this->channelNumber;

        if ($channelNumber == 0) {
            $channelNumber = 1;
        } else {
            $channelNumber = 0;
        }

        reset($this->messages[$channelNumber]);
        if ($this->messages[$channelNumber]) {
            $result = array_shift($this->messages[$channelNumber]);
        }

        return $result;
    }

    /**
     * Receives all messages from the queue.
     *
     * @return mixed Received messages.
     */
    public function receiveAll()
    {
        $channelNumber = $this->channelNumber;

        if ($channelNumber == 0) {
            $channelNumber = 1;
        } else {
            $channelNumber = 0;
        }

        $result = $this->messages[$channelNumber];
        $this->messages[$channelNumber] = [];

        if (!is_array($result)) {
            $result = [];
        }

        return $result;
    }

    /**
     * Destroys this IPC object.
     *
     * @param int $channelNumber
     * @return $this
     */
    public function disconnect($channelNumber = -1)
    {
        return $this;
    }

    /**
     * @return bool
     */
    public static function isSupported()
    {
        return true;
    }

    /**
     * @param int $channelNumber
     * @return $this
     */
    public function useChannelNumber($channelNumber)
    {
        $this->channelNumber = $channelNumber;

        return $this;
    }
}