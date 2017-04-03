<?php

namespace ZeusBench;

use Athletic\AthleticEvent;
use Zend\Cache\Storage\Adapter\Memory;
use Zeus\ServerService\Memcache\Message\Message;
use ZeusTest\Helpers\TestConnection;

class MemcachedMessageBenchmark extends AthleticEvent
{
    /** @var Message */
    protected $message;

    protected $connection;

    public function setUp()
    {
        $this->connection = new TestConnection();
        $this->message = new Message(new Memory(), new Memory());
        $this->message->onOpen($this->connection);
        $this->send("set test-key 12121212 10 2\r\nOK\r\n");
    }

    /**
     * @iterations 5000
     */
    public function setCommand()
    {
        $key = md5(rand(1, 100000));
        $value = md5(rand(1, 100000));
        $length = strlen($value);

        $this->send("set $key 12121212 10 $length\r\n$value\r\n");
    }

    /**
     * @iterations 5000
     */
    public function getCommand()
    {
        $this->send("get test-key\r\n");
    }

    protected function send($message)
    {
        $this->message->onMessage($this->connection, $message);
        $response = $this->connection->getSentData();

        return $response;
    }
}