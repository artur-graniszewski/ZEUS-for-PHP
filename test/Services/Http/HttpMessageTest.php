<?php

namespace ZeusTest\Services\Http;

use PHPUnit_Framework_TestCase;
use Zend\Http\Header\ContentLength;
use Zend\Http\Header\TransferEncoding;
use Zend\Http\Request;
use Zend\Http\Response;
use Zeus\ServerService\Http\Message\Message;
use Zeus\ServerService\Shared\React\ConnectionInterface;
use Zeus\ServerService\Shared\React\HeartBeatMessageInterface;
use Zeus\ServerService\Shared\React\MessageComponentInterface;
use ZeusTest\Helpers\TestConnection;

class HttpMessageTest extends PHPUnit_Framework_TestCase
{
    protected function getTmpDir()
    {
        $tmpDir = __DIR__ . '/../../tmp/';

        if (!file_exists($tmpDir)) {
            mkdir($tmpDir);
        }
        return $tmpDir;
    }

    public function setUp()
    {
        parent::setUp();

        ob_start();
    }

    public function tearDown()
    {
        ob_end_clean();

        $files = glob($this->getTmpDir() . '*');

        foreach ($files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        rmdir($this->getTmpDir());
        parent::tearDown();
    }

    public function testIfMessageHasBeenDispatched()
    {
        $testConnection = new TestConnection();
        $message = $this->getHttpGetRequestString("/");
        $dispatcherLaunched = false;
        /** @var Message $httpAdapter */
        $httpAdapter = $this->getHttpMessageParser(function() use (& $dispatcherLaunched) {$dispatcherLaunched = true;}, null, $testConnection);
        $httpAdapter->onMessage($testConnection, $message);

        $this->assertTrue($dispatcherLaunched, "Dispatcher should be called");

        $this->assertEquals(1, $httpAdapter->getNumberOfFinishedRequests());
    }

    public function testIfHttp10ConnectionIsClosedAfterSingleRequest()
    {
        $message = $this->getHttpGetRequestString("/");
        $testConnection = new TestConnection();
        $httpAdapter = $this->getHttpMessageParser(function() {}, null, $testConnection);
        $httpAdapter->onMessage($testConnection, $message);

        $this->assertTrue($testConnection->isConnectionClosed(), "HTTP 1.0 connection should be closed after request");
    }

    public function testIfHttp10KeepAliveConnectionIsOpenAfterSingleRequest()
    {
        $message = $this->getHttpGetRequestString("/", ["Connection" => "keep-alive"]);
        $testConnection = new TestConnection();
        $httpAdapter = $this->getHttpMessageParser(function() {}, null, $testConnection);
        $httpAdapter->onMessage($testConnection, $message);

        $this->assertFalse($testConnection->isConnectionClosed(), "HTTP 1.0 keep-alive connection should be left open after request");
    }

    public function testIfHttp11ConnectionIsOpenAfterSingleRequest()
    {
        $message = $this->getHttpGetRequestString("/", ['Host' => 'localhost'], "1.1");
        $testConnection = new TestConnection();
        $httpAdapter = $this->getHttpMessageParser(function() {}, null, $testConnection);
        $httpAdapter->onMessage($testConnection, $message);

        $this->assertFalse($testConnection->isConnectionClosed(), "HTTP 1.1 connection should be left open after request");
    }

    public function testIfHttp11ConnectionIsClosedAfterTimeout()
    {
        $message = $this->getHttpGetRequestString("/", ['Host' => 'localhost'], "1.1");
        $testConnection = new TestConnection();
        /** @var HeartBeatMessageInterface|MessageComponentInterface $httpAdapter */
        $httpAdapter = $this->getHttpMessageParser(function() {}, null, $testConnection);
        $httpAdapter->onMessage($testConnection, $message);

        $this->assertFalse($testConnection->isConnectionClosed(), "HTTP 1.1 connection should be left open after request");

        for($i = 0; $i < 5; $i++) {
            // simulate 5 seconds
            $httpAdapter->onHeartBeat($testConnection);
        }

        $this->assertTrue($testConnection->isConnectionClosed(), "HTTP 1.1 connection should be closed after keep-alive timeout");
    }

    public function responseBodyProvider()
    {
        return [
            ['TEST MESSAGE!'],
            ["TEST\nMessage\n!"],
            [str_pad('TEST STRING', 10000, '-', STR_PAD_RIGHT)],
        ];
    }

    /**
     * @param string $responseBody
     * @dataProvider responseBodyProvider
     */
    public function testIfResponseBodyIsCorrect($responseBody)
    {
        $message = $this->getHttpGetRequestString("/", ['Host' => 'localhost'], "1.1");
        $testConnection = new TestConnection();
        /** @var HeartBeatMessageInterface|MessageComponentInterface $httpAdapter */
        $httpAdapter = $this->getHttpMessageParser(function() use ($responseBody) {
            echo $responseBody;
        }, null, $testConnection);

        $httpAdapter->onMessage($testConnection, $message);

        $rawResponse = Response::fromString($testConnection->getSentData());
        $this->assertEquals($responseBody, $rawResponse->getBody());
    }

    /**
     * @param string $responseBody
     * @dataProvider responseBodyProvider
     */
    public function testIfChunkedResponseBodyIsCorrect($responseBody)
    {
        $message = $this->getHttpGetRequestString("/", ['Host' => 'localhost'], "1.1");
        $testConnection = new TestConnection();
        /** @var HeartBeatMessageInterface|MessageComponentInterface $httpAdapter */
        $httpAdapter = $this->getHttpMessageParser(function() use ($responseBody) {
            echo $responseBody;

            $response = new Response();
            $response->getHeaders()->addHeader(new TransferEncoding("chunked"));

            return $response;
        }, null, $testConnection);

        $httpAdapter->onMessage($testConnection, $message);

        $rawResponse = Response::fromString($testConnection->getSentData());
        $this->assertEquals($responseBody, $rawResponse->getBody());
    }

    /**
     * @param string $responseBody
     * @dataProvider responseBodyProvider
     */
    public function testIfGzippedResponseBodyIsCorrect($responseBody)
    {
        $message = $this->getHttpGetRequestString("/", ['Host' => 'localhost', 'Accept-Encoding' => 'gzip, deflate'], "1.1");
        $testConnection = new TestConnection();
        /** @var HeartBeatMessageInterface|MessageComponentInterface $httpAdapter */
        $httpAdapter = $this->getHttpMessageParser(function() use ($responseBody) {
            echo $responseBody;
        }, null, $testConnection);

        $httpAdapter->onOpen($testConnection);
        $httpAdapter->onMessage($testConnection, $message);

        $rawResponse = Response::fromString($testConnection->getSentData());

        if (strlen($responseBody) >= 8192) {
            $this->assertEquals('deflate', $rawResponse->getHeaders()->get('Content-Encoding')->getFieldValue());
            $this->assertLessThan(strlen($responseBody), $rawResponse->getHeaders()->get('Content-Length')->getFieldValue());
        } else {
            $this->assertFalse($rawResponse->getHeaders()->has('Content-Encoding'));
        }
        $this->assertEquals($responseBody, $rawResponse->getBody());

    }

    public function testIfHttp11ConnectionIsClosedWithConnectionHeaderAfterSingleRequest()
    {
        $message = $this->getHttpGetRequestString("/", ["Connection" => "close", 'Host' => '127.0.0.1:80'], "1.1");
        $testConnection = new TestConnection();
        $httpAdapter = $this->getHttpMessageParser(function() {}, null, $testConnection);
        $httpAdapter->onMessage($testConnection, $message);

        $this->assertTrue($testConnection->isConnectionClosed(), "HTTP 1.1 connection should be closed when Connection: close header is present");
    }

    /**
     * @expectedException \InvalidArgumentException
     * @expectedExceptionMessage Missing host header
     * @expectedExceptionCode 400
     */
    public function testIfHttp11HostHeaderIsMandatory()
    {
        $message = $this->getHttpGetRequestString("/", [], "1.1");
        $testConnection = new TestConnection();
        /** @var Response $response */
        $response = null;
        $requestHandler = function($_request, $_response) use (&$response) {$response = $_response; };
        $httpAdapter = $this->getHttpMessageParser($requestHandler, null, $testConnection);
        $httpAdapter->onMessage($testConnection, $message);
        $rawResponse = Response::fromString($testConnection->getSentData());

        $this->assertEquals(400, $rawResponse->getStatusCode(), "HTTP/1.1 request with missing host header should generate 400 error message");

        $testConnection = new TestConnection();
        $message = $this->getHttpGetRequestString("/", ['Host' => 'localhost'], "1.1");
        $httpAdapter->onMessage($testConnection, $message);
        $rawResponse = Response::fromString($testConnection->getSentData());

        $this->assertEquals(200, $rawResponse->getStatusCode(), "HTTP/1.1 request with valid host header should generate 200 OK message");
    }

    public function testIfPostDataIsCorrectlyInterpreted()
    {
        $postData = ["test1" => "test2", "test3" => "test4", "test4" => ["aaa" => "bbb"], "test5" => 12];
        $message = $this->getHttpPostRequestString("/", [], $postData);
        for($chunkSize = 1, $messageSize = strlen($message); $chunkSize < $messageSize; $chunkSize++) {
            $testConnection = new TestConnection();
            /** @var Request $request */
            $request = null;

            $errorOccured = false;

            $errorHandler = function($request, $exception) use (& $errorOccured) {
                $errorOccured = $exception;
            };

            $requestHandler = function ($_request) use (&$request) {
                $request = $_request;
            };
            $httpAdapter = $this->getHttpMessageParser($requestHandler, $errorHandler);
            $httpAdapter->onOpen($testConnection);

            $chunks = str_split($message, $chunkSize);
            foreach ($chunks as $index => $chunk) {
                $httpAdapter->onMessage($testConnection, $chunk);

                if ($errorOccured) {
                    $this->fail("Error handler caught an error when parsing chunk #$index: " . $errorOccured->getMessage());
                }
            }

            $this->assertEquals("/", $request->getUri()->getPath());
            foreach ($postData as $key => $value) {
                $this->assertEquals($value, $request->getPost($key), "Request object should contain valid POST data for key $key");
            }
        }
    }

    public function getQueryData()
    {
        return [
            ["test" => "ok"],
            ["test1" => "ok", "test2" => "test2"],
            ["test1" => "test2", "test3" => "test4", "test4" => ["aaa" => "bbb"], "test5" => 12]
        ];
    }

    /**
     * @dataProvider getQueryData
     */
    public function testIfQueryDataIsCorrectlyInterpreted()
    {
        $queryData = func_get_args();
        $queryString = http_build_query($queryData);
        $message = $this->getHttpGetRequestString("/test?" . $queryString);
        $testConnection = new TestConnection();
        for($chunkSize = 1, $messageSize = strlen($message); $chunkSize < $messageSize; $chunkSize++) {
            /** @var Request $request */
            $request = null;
            $errorOccurred = false;

            $errorHandler = function($request, $exception) use (& $errorOccurred) {
                $errorOccurred = $exception;
            };

            $requestHandler = function ($_request) use (&$request) {
                $request = $_request;
            };
            $httpAdapter = $this->getHttpMessageParser($requestHandler, $errorHandler);
            $httpAdapter->onOpen($testConnection);

            $chunks = str_split($message, $chunkSize);
            foreach ($chunks as $index => $chunk) {
                $httpAdapter->onMessage($testConnection, $chunk);

                if ($errorOccurred) {
                    $this->fail("Error handler caught an error when parsing chunk #$index: " . $errorOccurred->getMessage());
                }
            }

            $this->assertEquals("/test", $request->getUri()->getPath());
            foreach ($queryData as $key => $value) {
                $this->assertEquals($value, $request->getQuery($key), "Request object should contain valid GET data for key $key");
            }
        }
    }

    public function testIfOptionsHeadAndTraceReturnEmptyBody()
    {
        foreach (["HEAD", "TRACE", "OPTIONS"] as $method) {
            $testString = "$method test string";

            $message = $this->getHttpCustomMethodRequestString($method, "/", []);
            $testConnection = new TestConnection();
            /** @var Request $request */
            $request = null;
            $requestHandler = function(Request $_request, Response $_response) use (&$request, &$response, $testString) {
                $request = $_request;
                $_response->getHeaders()->addHeader(new ContentLength(strlen($testString)));
                echo $testString;
            };
            $httpAdapter = $this->getHttpMessageParser($requestHandler, $requestHandler);
            $httpAdapter->onOpen($testConnection);
            $httpAdapter->onMessage($testConnection, $message);
            $rawResponse = Response::fromString($testConnection->getSentData());

            $this->assertEquals(0, strlen($rawResponse->getBody()), "No content should be returned by $method response");
            $this->assertEquals(strlen($testString), $rawResponse->getHeaders()->get('Content-Length')->getFieldValue(), "Incorrect Content-Length header returned by $method response");
        }
    }

    public function testIfMultipleRequestsAreHandledByOneMessageInstance()
    {
        $testString = '';
        $requestHandler = function($_request) use (&$request, &$response, & $testString) {$request = $_request; echo $testString; };
        $testConnection = new TestConnection();

        /** @var Request $request */
        $request = null;
        $httpAdapter = $this->getHttpMessageParser($requestHandler);
        $httpAdapter->onOpen($testConnection);

        for($i = 1; $i < 10; $i++) {
            $pad = str_repeat("A", $i);
            $testString = "$pad test string";

            $message = $this->getHttpCustomMethodRequestString('GET', "/", ['host' => 'localhost'] ,'1.1');

            $httpAdapter->onMessage($testConnection, $message);
            $rawResponse = Response::fromString($testConnection->getSentData());

            $this->assertEquals($testString, $rawResponse->getBody(), "Original content should be returned in response");
        }
    }

    public function testIfRequestBodyIsReadCorrectly()
    {
        $fileContent = ['Content of a.txt.', '<!DOCTYPE html><title>Content of a.html.</title>', 'aωb'];

        $message = $this->getFileUploadRequest('POST', $fileContent);

        for($chunkSize = 1, $messageSize = strlen($message); $chunkSize < $messageSize; $chunkSize++) {
            $testConnection = new TestConnection();
            /** @var Request $request */
            $request = null;
            $fileList = [];
            $tmpDir = $this->getTmpDir();

            $errorOccurred = false;

            $errorHandler = function($request, $exception) use (& $errorOccurred) {
                $errorOccurred = $exception;
            };
            $requestHandler = function (Request $_request) use (&$request, & $fileList, $tmpDir) {
                $request = $_request;

                foreach ($request->getFiles() as $formName => $fileArray) {
                    foreach ($fileArray as $file) {
                        rename($file['tmp_name'], $tmpDir . $file['name']);
                        $fileList[$formName] = $file['name'];
                    }
                }
            };
            $httpAdapter = $this->getHttpMessageParser($requestHandler, $errorHandler);
            $httpAdapter->onOpen($testConnection);

            $chunks = str_split($message, $chunkSize);
            foreach ($chunks as $index => $chunk) {
                $httpAdapter->onMessage($testConnection, $chunk);

                if ($errorOccurred) {
                    $this->fail("Error handler caught an error when parsing chunk #$index: " . $errorOccurred->getMessage());
                }
            }

            $rawResponse = Response::fromString($testConnection->getSentData());

            $this->assertEquals(200, $rawResponse->getStatusCode(), "HTTP response should return 200 OK status, message received: " . $rawResponse->getContent());
            $this->assertEquals(3, $request->getFiles()->count(), "HTTP request contains 3 files but Request object reported " . $request->getFiles()->count());
            foreach ($fileContent as $index => $content) {
                $name = "file" . ($index + 1);
                $this->assertEquals($content, file_get_contents($this->getTmpDir() . $fileList[$name]), "Content of the uploaded file should match the original for file " . $fileList[$name]);
            }
        }
    }

    public function testRegularPostRequestWithBody()
    {
        $message = "POST / HTTP/1.0
Content-Length: 11

Hello_World";
        for($chunkSize = 1, $messageSize = strlen($message); $chunkSize < $messageSize; $chunkSize++) {
            $testConnection = new TestConnection();
            /** @var Request $request */
            $request = null;
            $fileList = [];
            $tmpDir = $this->getTmpDir();

            $errorOccurred = false;

            $errorHandler = function ($request, $exception) use (& $errorOccurred) {
                $errorOccurred = $exception;
            };


            $requestHandler = function (Request $_request) use (&$request, & $fileList, $tmpDir) {
                $request = $_request;
                return new Response();
            };

            $httpAdapter = $this->getHttpMessageParser($requestHandler, $errorHandler);
            $httpAdapter->onOpen($testConnection);

            $chunks = str_split($message, $chunkSize);
            foreach ($chunks as $index => $chunk) {
                $httpAdapter->onMessage($testConnection, $chunk);

                if ($errorOccurred) {
                    $this->fail("Error handler caught an error when parsing chunk #$index: " . $errorOccurred->getMessage());
                }
            }

            $rawResponse = Response::fromString($testConnection->getSentData());

            $this->assertEquals("Hello_World", $request->getContent(), "HTTP response should have returned 'Hello_World', received: " . $request->getContent());
        }
    }

    public function testChunkedPostRequest()
    {
        $message = "POST / HTTP/1.0
Transfer-Encoding: chunked

6
Hello_
5
World
0

";
        for($chunkSize = 1, $messageSize = strlen($message); $chunkSize < $messageSize; $chunkSize++) {
            $testConnection = new TestConnection();
            /** @var Request $request */
            $request = null;
            $fileList = [];
            $tmpDir = $this->getTmpDir();

            $errorOccurred = false;

            $errorHandler = function ($request, $exception) use (& $errorOccurred) {
                $errorOccurred = $exception;
            };

            $requestHandler = function (Request $_request) use (&$request, & $fileList, $tmpDir) {
                $request = $_request;
                return new Response();
            };

            $httpAdapter = $this->getHttpMessageParser($requestHandler, $errorHandler, $testConnection);

            $chunks = str_split($message, $chunkSize);
            foreach ($chunks as $index => $chunk) {
                $httpAdapter->onMessage($testConnection, $chunk);

                if ($errorOccurred) {
                    $this->fail("Error handler caught an error when parsing chunk #$index: " . $errorOccurred->getMessage());
                }
            }

            try {
                $rawResponse = Response::fromString($testConnection->getSentData());
            } catch (\Exception $e) {
                $this->fail("Invalid response detected in chunk $chunkSize: " . json_encode($chunks));
                $this->fail("Invalid response detected in chunk $chunkSize: " . $testConnection->getSentData());
            }
            $this->assertEquals(200, $rawResponse->getStatusCode(), "HTTP response should return 200 OK status, message received: " . $rawResponse->getContent());
            $this->assertEquals("Hello_World", $request->getContent(), "HTTP response should have returned 'Hello_World', received: " . $request->getContent());
        }
    }

    public function testIfRequestBodyIsNotAvailableInFileUploadMode()
    {
        $fileContent = ['Content of a.txt.', '<!DOCTYPE html><title>Content of a.html.</title>', 'aωb'];

        $message = $this->getFileUploadRequest('POST', $fileContent);

        $testConnection = new TestConnection();
        /** @var Request $request */
        $request = null;
        $requestHandler = function($_request) use (&$request) {$request = $_request; };
        $httpAdapter = $this->getHttpMessageParser($requestHandler, $requestHandler);
        $httpAdapter->onOpen($testConnection);
        $httpAdapter->onMessage($testConnection, $message);
        $rawResponse = Response::fromString($testConnection->getSentData());

        $this->assertEquals(200, $rawResponse->getStatusCode(), "HTTP response should return 200 OK status, message received: " . $rawResponse->getContent());
        $this->assertEquals(3, $request->getFiles()->count(), "HTTP request contains 3 files but Request object reported " . $request->getFiles()->count());
        $this->assertEquals(0, strlen($request->getContent()), "No content should be present in request object in case of multipart data: " . $request->getContent());
    }

    /**
     * @return mixed[]
     */
    public function getInvalidMessages()
    {
        return [
            ["\r\n\r\n"],
            ["DUMMY / HTTP/1.0\r\n\r\n"],
            ["GET / HTTP/10.1\r\n\r\n"],
        ];
    }

    /**
     * @dataProvider getInvalidMessages
     * @expectedException \InvalidArgumentException
     * @expectedExceptionMessageRegExp /Incorrect headers/
     * @param string $message
     */
    public function testIfMessageWithInvalidHeadersIsHandled($message)
    {
        $dispatcherLaunched = false;
        $testConnection = new TestConnection();
        /** @var Message $httpAdapter */
        $httpAdapter = $this->getHttpMessageParser(function() use (& $dispatcherLaunched) {$dispatcherLaunched = true;}, null, $testConnection);
        $httpAdapter->onMessage($testConnection, $message);

        $this->assertTrue($dispatcherLaunched, "Dispatcher should be called");

        $this->assertEquals(1, $httpAdapter->getNumberOfFinishedRequests());
    }

    protected function getBuffer()
    {
        $result = ob_get_clean();
        ob_start();

        return $result;
    }

    /**
     * @param callback $dispatcher
     * @param callback $errorHandler
     * @param ConnectionInterface $connection
     * @return MessageComponentInterface
     */
    protected function getHttpMessageParser($dispatcher, $errorHandler = null, ConnectionInterface $connection = null)
    {
        $dispatcherWrapper = function($request, $response) use ($dispatcher) {
            $dispatcher($request, $response);
        };

        $adapter = new Message($dispatcherWrapper, $errorHandler);
        if ($connection) {
            $adapter->onOpen($connection);
        }

        return $adapter;
    }

    protected function getHttpGetRequestString($uri, $headers = [], $protocolVersion = '1.0')
    {
        $request = "GET $uri HTTP/$protocolVersion\r\n";

        foreach ($headers as $headerName => $headerValue) {
            $request .= "$headerName: $headerValue\r\n";
        }


        $request .= "\r\n";

        return $request;
    }

    protected function getHttpCustomMethodRequestString($method, $uri, $headers = [], $protocolVersion = '1.0')
    {
        $request = "$method $uri HTTP/$protocolVersion\r\n";

        foreach ($headers as $headerName => $headerValue) {
            $request .= "$headerName: $headerValue\r\n";
        }

        $request .= "\r\n";

        return $request;
    }

    protected function getHttpPostRequestString($uri, $headers = [], $postData = [], $protocolVersion = '1.0')
    {
        $request = "POST $uri HTTP/$protocolVersion\r\n";

        if (is_array($postData)) {
            $postData = http_build_query($postData);
        }

        if (!isset($headers['Content-Length'])) {
            $headers['Content-Length'] = strlen($postData);
        }

        if (!isset($headers['Content-Type'])) {
            $headers['Content-Type'] = 'application/x-www-form-urlencoded';
        }

        foreach ($headers as $headerName => $headerValue) {
            $request .= "$headerName: $headerValue\r\n";
        }

        $request .= "\r\n$postData";

        return $request;
    }

    protected function getFileUploadRequest($requestType, $fileContent)
    {
        $message =
            $requestType . ' / HTTP/1.0
Content-Type: multipart/form-data; boundary=---------------------------735323031399963166993862150
Content-Length: 834

-----------------------------735323031399963166993862150
Content-Disposition: form-data; name="text1"

text default
-----------------------------735323031399963166993862150
Content-Disposition: form-data; name="text2"

aωb
-----------------------------735323031399963166993862150
Content-Disposition: form-data; name="file1"; filename="a.txt"
Content-Type: text/plain

' . $fileContent[0] . '

-----------------------------735323031399963166993862150
Content-Disposition: form-data; name="file2"; filename="a.html"
Content-Type: text/html

' . $fileContent[1] . '

-----------------------------735323031399963166993862150
Content-Disposition: form-data; name="file3"; filename="binary"
Content-Type: application/octet-stream

' . $fileContent[2] . '
-----------------------------735323031399963166993862150--';

        return $message;
    }
}