<?php

namespace ZeusTest;

use PHPUnit_Framework_TestCase;
use Zend\Http\Request;
use Zend\Http\Response;
use Zeus\ServerService\Http\Message\Message;
use Zeus\ServerService\Shared\React\MessageComponentInterface;
use ZeusTest\Helpers\TestConnection;

class HttpAdapterTest extends PHPUnit_Framework_TestCase
{
    protected function getTmpDir()
    {
        return __DIR__ . '/tmp/';
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

        parent::tearDown();
    }

    public function testIfMessageHasBeenDispatched()
    {
        $message = $this->getHttpGetRequestString("/");
        $dispatcherLaunched = false;
        $this->getHttpAdapter(function() use (& $dispatcherLaunched) {$dispatcherLaunched = true;})->onMessage(new TestConnection(), $message);

        $this->assertTrue($dispatcherLaunched, "Dispatcher should be called");
    }

    public function testIfHttp10ConnectionIsClosedAfterSingleRequest()
    {
        $message = $this->getHttpGetRequestString("/");
        $testConnection = new TestConnection();
        $this->getHttpAdapter(function() {})->onMessage($testConnection, $message);

        $this->assertTrue($testConnection->isConnectionClosed(), "HTTP 1.0 connection should be closed after request");
    }

    public function testIfHttp10KeepAliveConnectionIsOpenAfterSingleRequest()
    {
        $message = $this->getHttpGetRequestString("/", ["Connection" => "keep-alive"]);
        $testConnection = new TestConnection();
        $this->getHttpAdapter(function() {})->onMessage($testConnection, $message);

        $this->assertFalse($testConnection->isConnectionClosed(), "HTTP 1.0 keep-alive connection should be left open after request");
    }

    public function testIfHttp11ConnectionIsOpenAfterSingleRequest()
    {
        $message = $this->getHttpGetRequestString("/", ['Host' => 'localhost'], "1.1");
        $testConnection = new TestConnection();
        $this->getHttpAdapter(function() {})->onMessage($testConnection, $message);

        $this->assertFalse($testConnection->isConnectionClosed(), "HTTP 1.1 connection should be left open after request");
    }

    public function testIfHttp11ConnectionIsClosedWithConnectionHeaderAfterSingleRequest()
    {
        $message = $this->getHttpGetRequestString("/", ["Connection" => "close", 'Host' => 'localhost'], "1.1");
        $testConnection = new TestConnection();
        $this->getHttpAdapter(function() {})->onMessage($testConnection, $message);

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
        $httpAdapter = $this->getHttpAdapter($requestHandler);
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
            /** @var Response $response */
            $response = null;

            $errorOccured = false;

            $errorHandler = function($request, $response, $exception) use (& $errorOccured) {
                $errorOccured = $exception;
            };

            $requestHandler = function ($_request, $_response) use (&$request, &$response) {
                $request = $_request;
                $response = $_response;
            };
            $httpAdapter = $this->getHttpAdapter($requestHandler, $errorHandler);

            $chunks = str_split($message, $chunkSize);
            foreach ($chunks as $index => $chunk) {
                $httpAdapter->onMessage($testConnection, $chunk);

                if ($errorOccured) {
                    $this->fail("Error handler caught an error when parsing chunk #$index: " . $errorOccured->getMessage());
                }
            }

            $this->assertEquals(200, $response->getStatusCode(), "HTTP/1.1 request should generate 200 OK message");
            $this->assertEquals("/", $request->getUriString());
            foreach ($postData as $key => $value) {
                $this->assertEquals($value, $request->getPost($key), "Request object should contain valid POST data for key $key");
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
            /** @var Response $response */
            $response = null;
            $requestHandler = function($_request, $_response) use (&$request, &$response, $testString) {$request = $_request; $response = $_response; echo $testString; };
            $httpAdapter = $this->getHttpAdapter($requestHandler, $requestHandler);
            $httpAdapter->onMessage($testConnection, $message);
            $rawResponse = Response::fromString($testConnection->getSentData());

            $this->assertEquals(0, strlen($rawResponse->getBody()), "No content should be returned by $method response");
            $this->assertEquals(strlen($testString), $response->getHeaders()->get('Content-Length')->getFieldValue(), "Incorrect Content-Length header returned by $method response");

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
            /** @var Response $response */
            $response = null;
            $fileList = [];
            $tmpDir = $this->getTmpDir();

            $errorOccured = false;

            $errorHandler = function($request, $response, $exception) use (& $errorOccured) {
                $errorOccured = $exception;
            };
            $requestHandler = function (Request $_request, Response $_response) use (&$request, &$response, & $fileList, $tmpDir) {
                $request = $_request;
                $response = $_response;

                foreach ($request->getFiles() as $formName => $fileArray) {
                    foreach ($fileArray as $file) {
                        rename($file['tmp_name'], $tmpDir . $file['name']);
                        $fileList[$formName] = $file['name'];
                    }
                }
            };
            $httpAdapter = $this->getHttpAdapter($requestHandler, $errorHandler);

            $chunks = str_split($message, $chunkSize);
            foreach ($chunks as $index => $chunk) {
                $httpAdapter->onMessage($testConnection, $chunk);

                if ($errorOccured) {
                    $this->fail("Error handler caught an error when parsing chunk #$index: " . $errorOccured->getMessage());
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
            /** @var Response $response */
            $response = null;
            $fileList = [];
            $tmpDir = $this->getTmpDir();

            $errorOccured = false;

            $errorHandler = function ($request, $response, $exception) use (& $errorOccured) {
                $errorOccured = $exception;
            };


            $requestHandler = function (Request $_request, Response $_response) use (&$request, &$response, & $fileList, $tmpDir) {
                $request = $_request;
                $response = $_response;
                return $request;
            };

            $httpAdapter = $this->getHttpAdapter($requestHandler, $errorHandler);

            $chunks = str_split($message, $chunkSize);
            foreach ($chunks as $index => $chunk) {
                $httpAdapter->onMessage($testConnection, $chunk);

                if ($errorOccured) {
                    $this->fail("Error handler caught an error when parsing chunk #$index: " . $errorOccured->getMessage());
                }
            }

            $rawResponse = Response::fromString($testConnection->getSentData());

            $this->assertEquals(200, $rawResponse->getStatusCode(), "HTTP response should return 200 OK status, message received: " . $rawResponse->getContent());
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
            /** @var Response $response */
            $response = null;
            $fileList = [];
            $tmpDir = $this->getTmpDir();

            $errorOccured = false;

            $errorHandler = function ($request, $response, $exception) use (& $errorOccured) {
                $errorOccured = $exception;
            };

            $requestHandler = function (Request $_request, Response $_response) use (&$request, &$response, & $fileList, $tmpDir) {
                $request = $_request;
                $response = $_response;
                return $request;
            };

            $httpAdapter = $this->getHttpAdapter($requestHandler, $errorHandler);

            $chunks = str_split($message, $chunkSize);
            foreach ($chunks as $index => $chunk) {
                $httpAdapter->onMessage($testConnection, $chunk);

                if ($errorOccured) {
                    $this->fail("Error handler caught an error when parsing chunk #$index: " . $errorOccured->getMessage());
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
        /** @var Response $response */
        $response = null;
        $requestHandler = function($_request, $_response) use (&$request, &$response) {$request = $_request; $response = $_response; };
        $httpAdapter = $this->getHttpAdapter($requestHandler, $requestHandler);
        $httpAdapter->onMessage($testConnection, $message);
        $rawResponse = Response::fromString($testConnection->getSentData());

        $this->assertEquals(200, $rawResponse->getStatusCode(), "HTTP response should return 200 OK status, message received: " . $rawResponse->getContent());
        $this->assertEquals(3, $request->getFiles()->count(), "HTTP request contains 3 files but Request object reported " . $request->getFiles()->count());
        $this->assertEquals(0, strlen($request->getContent()), "No content should be present in request object in case of multipart data: " . $request->getContent());
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
     * @return MessageComponentInterface
     */
    protected function getHttpAdapter($dispatcher, $errorHandler = null)
    {
        $adapter = new Message($dispatcher, $errorHandler);

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