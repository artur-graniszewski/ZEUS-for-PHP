<?php

namespace ZeusBench\Http;

use Athletic\AthleticEvent;
use Zend\Http\Request;
use Zend\Http\Response;
use Zeus\ServerService\Http\Message\Message;
use ZeusTest\Helpers\SocketTestNetworkStream;

class HttpMessageBenchmark extends AthleticEvent
{
    /** @var Message */
    protected $message;

    protected $connection;

    protected $largeFile;

    protected $mediumFile;

    protected $smallFile;

    public function __construct()
    {
        $this->largeFile = str_repeat('AbCcDd', 65535);
        $this->mediumFile = str_repeat('Ab', 65535);
        $this->smallFile = str_repeat('AB', 4097);
    }

    public function setUp()
    {
        $this->connection = new SocketTestNetworkStream(null);
        $this->message = new Message(function(Request $request) {
            switch ($request->getUri()->getPath()) {
                case '/large.txt':
                    echo $this->largeFile;
                    break;

                case '/medium.txt':
                    echo $this->mediumFile;
                    break;

                case '/small.txt':
                    echo $this->smallFile;
            }
        });
        $this->message->onOpen($this->connection);
    }

    public function tearDown()
    {
        $this->message->onClose($this->connection);
        unset($this->message);
        unset($this->connection);
    }

    protected function getHttpRequestString($method, $uri, $headers = [], $protocolVersion = '1.0')
    {
        $request = "$method $uri HTTP/$protocolVersion\r\n";

        foreach ($headers as $headerName => $headerValue) {
            $request .= "$headerName: $headerValue\r\n";
        }


        $request .= "\r\n";

        return $request;
    }

    /**
     * @iterations 5000
     */
    public function getLargeRequest()
    {
        $this->message->onMessage($this->connection, $this->getHttpRequestString('GET', '/large.txt'));
    }

    /**
     * @iterations 5000
     */
    public function getMediumRequest()
    {
        $this->message->onMessage($this->connection, $this->getHttpRequestString('GET', '/medium.txt'));
    }

    /**
     * @iterations 5000
     */
    public function getSmallRequest()
    {
        $this->message->onMessage($this->connection, $this->getHttpRequestString('GET', '/small.txt'));
    }

    /**
     * @iterations 5000
     */
    public function getDeflatedLargeRequest()
    {
        $this->message->onMessage($this->connection, $this->getHttpRequestString('GET', '/large.txt', ['Accept-Encoding' => 'gzip']));
    }

    /**
     * @iterations 5000
     */
    public function getDeflatedMediumRequest()
    {
        $this->message->onMessage($this->connection, $this->getHttpRequestString('GET', '/medium.txt', ['Accept-Encoding' => 'gzip']));
    }

    /**
     * @iterations 5000
     */
    public function getDeflatedSmallRequest()
    {
        $this->message->onMessage($this->connection, $this->getHttpRequestString('GET', '/small.txt', ['Accept-Encoding' => 'gzip']));
    }

    /**
     * @iterations 5000
     */
    public function optionsLargeRequest()
    {
        $this->message->onMessage($this->connection, $this->getHttpRequestString('OPTIONS', '/large.txt'));
    }

    /**
     * @iterations 5000
     */
    public function optionsMediumRequest()
    {
        $this->message->onMessage($this->connection, $this->getHttpRequestString('OPTIONS', '/medium.txt'));
    }

    /**
     * @iterations 5000
     */
    public function optionsSmallRequest()
    {
        $this->message->onMessage($this->connection, $this->getHttpRequestString('OPTIONS', '/small.txt'));
    }

    /**
     * @iterations 5000
     */
    public function optionsDeflatedLargeRequest()
    {
        $this->message->onMessage($this->connection, $this->getHttpRequestString('OPTIONS', '/large.txt', ['Accept-Encoding' => 'gzip']));
    }

    /**
     * @iterations 5000
     */
    public function optionsDeflatedMediumRequest()
    {
        $this->message->onMessage($this->connection, $this->getHttpRequestString('OPTIONS', '/medium.txt', ['Accept-Encoding' => 'gzip']));
    }

    /**
     * @iterations 5000
     */
    public function optionsDeflatedSmallRequest()
    {
        $this->message->onMessage($this->connection, $this->getHttpRequestString('OPTIONS', '/small.txt', ['Accept-Encoding' => 'gzip']));
    }
}