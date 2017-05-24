<?php

namespace ZeusBench\Memcached;

use Athletic\AthleticEvent;
use Zend\Cache\Storage\Adapter\Memory;
use Zeus\ServerService\Memcache\Message\Message;
use ZeusTest\Helpers\SocketTestNetworkStream;

class MemcachedMessageBenchmark extends AthleticEvent
{
    /** @var Message */
    protected $message;

    protected $connection;

    public function setUp()
    {
        $this->connection = new SocketTestNetworkStream(null);
        $this->message = new Message(new Memory(), new Memory());
        $this->message->onOpen($this->connection);
        $this->send("set test-key 12121212 10 2\r\nOK\r\n");
        $this->send("set test-key-int 12121212 10 5\r\n11000\r\n");
    }

    /**
     * @iterations 5000
     */
    public function setCommand()
    {
        $value = md5(rand(1, 100000));
        $length = strlen($value);

        $this->send("set $value 12121212 10 $length\r\n$value\r\n");
    }

    /**
     * @iterations 5000
     */
    public function getCommand()
    {
        $this->send("get test-key\r\n");
    }

    /**
     * @iterations 5000
     */
    public function incrCommand()
    {
        $this->send("incr test-key 1\r\n");
    }

    /**
     * @iterations 5000
     */
    public function decrCommand()
    {
        $this->send("decr test-key 1\r\n");
    }

    protected function send($message)
    {
        $this->message->onMessage($this->connection, $message);
        $response = $this->connection->getSentData();

        return $response;
    }
}