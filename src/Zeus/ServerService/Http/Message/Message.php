<?php

namespace Zeus\ServerService\Http\Message;

use InvalidArgumentException;
use Throwable;
use Zend\Http\Header\KeepAlive;
use Zeus\IO\Exception\IOException;
use Zeus\ServerService\Http\Message\Helper\ChunkedEncoding;
use Zeus\ServerService\Http\Message\Helper\Header;
use Zeus\ServerService\Http\Message\Helper\PostData;
use Zeus\ServerService\Http\Message\Helper\RegularEncoding;
use Zeus\ServerService\Http\Message\Helper\FileUpload;
use Zeus\ServerService\Shared\Networking\HeartBeatMessageInterface;
use Zeus\ServerService\Shared\Networking\MessageComponentInterface;
use Zeus\IO\Stream\NetworkStreamInterface;
use Zend\Http\Header\Connection;
use Zend\Http\Header\ContentEncoding;
use Zend\Http\Header\TransferEncoding;
use Zend\Http\Header\Vary;
use Zend\Http\Response\Stream as Response;

use function is_callable;
use function strlen;
use function ob_start;
use function ob_end_flush;
use function function_exists;
use function preg_match;
use function in_array;
use function explode;
use function sprintf;
use function str_replace;
use function deflate_init;
use function deflate_add;
use function fclose;
use function fread;
use function feof;
use function is_resource;
use function gmdate;
use function getmypid;

class Message implements MessageComponentInterface, HeartBeatMessageInterface
{
    use ChunkedEncoding;
    use RegularEncoding;
    use FileUpload;
    use Header;
    use PostData;

    const ENCODING_IDENTITY = 'identity';
    const ENCODING_CHUNKED = 'chunked';

    const REQUEST_PHASE_IDLE = 1;
    const REQUEST_PHASE_KEEP_ALIVE = 2;
    const REQUEST_PHASE_READING = 4;
    const REQUEST_PHASE_PROCESSING = 8;
    const REQUEST_PHASE_SENDING = 16;
    const MAX_KEEP_ALIVE_REQUESTS = 100;

    /** @var NetworkStreamInterface */
    protected $connection;

    /** @var int */
    protected $requestPhase = self::REQUEST_PHASE_IDLE;

    /** @var int */
    protected $bufferSize = 8192;

    /** @var callable */
    protected $errorHandler;

    /** @var Callback */
    protected $dispatcher;

    /** @var bool */
    protected $headersSent = false;

    /** @var int */
    protected $keepAliveCount;

    /** @var int */
    protected $keepAliveTimer = 5;

    /** @var TransferEncoding */
    protected $chunkedHeader;

    /** @var Connection */
    protected $closeHeader;

    /** @var Connection */
    protected $keepAliveHeader;

    /** @var bool */
    protected $requestComplete = false;

    /** @var int */
    protected $requestsFinished = 0;

    /** @var bool */
    protected $headersReceived = false;

    /** @var bool */
    protected $bodyReceived = false;

    /** @var Request */
    protected $request;

    /** @var Response */
    protected $response;

    /** @var int */
    protected $posInRequestBody = 0;

    protected $compressionHandler = null;

    protected $remoteAddress = '';

    /** @var KeepAlive */
    protected $keepAliveCounterHeader;

    /**
     * @var callable
     */
    private $responseHandler;

    /**
     * @param callable $dispatcher
     * @param callable $errorHandler
     * @param callable $responseHandler
     */
    public function __construct($dispatcher, $errorHandler = null, $responseHandler = null)
    {
        $this->errorHandler = $errorHandler;
        $this->chunkedHeader = new TransferEncoding(static::ENCODING_CHUNKED);
        $this->closeHeader = (new Connection())->setValue("close");
        $this->keepAliveHeader = (new Connection())->setValue("keep-alive");
        $this->keepAliveCounterHeader = new KeepAlive("timeout=" . $this->keepAliveTimer);
        $this->dispatcher = $dispatcher;
        $this->responseHandler = $responseHandler;
    }

    /**
     * @return callable
     */
    public function getErrorHandler()
    {
        return $this->errorHandler;
    }

    /**
     * @return callable
     */
    public function getDispatcher()
    {
        return $this->dispatcher;
    }

    public function onOpen(NetworkStreamInterface $connection)
    {
        $this->initNewRequest();
        $this->restartKeepAliveCounter();
        $this->connection = $connection;
        $this->requestPhase = static::REQUEST_PHASE_KEEP_ALIVE;
        $this->remoteAddress = $connection->getRemoteAddress();
    }

    /**
     * @param NetworkStreamInterface $connection
     * @param Throwable $exception
     * @throws Throwable
     */
    public function onError(NetworkStreamInterface $connection, Throwable $exception)
    {
        if (!$connection->isWritable()) {
            $this->onClose($connection);

            return;//throw $exception;
        }

        if (!$this->request) {
            $this->request = new Request();
            $this->request->setMetadata('remoteAddress', $this->remoteAddress);
        }

        $callback = function($request) use ($exception) {
            $errorHandler = $this->getErrorHandler();

            if (!is_callable($errorHandler)) {
                $errorHandler = [$this, 'dispatchError'];
            }

            return $errorHandler($request, $exception);
        };

        $this->requestComplete = true;
        $this->dispatchRequest($connection, $callback);

        if ($exception->getCode() === Response::STATUS_CODE_400) {
            $this->onClose($connection);
        }

        if (!$exception instanceof IOException) {
            throw $exception;
        }
    }

    public function onClose(NetworkStreamInterface $connection)
    {
        $this->requestPhase = static::REQUEST_PHASE_IDLE;
        if (!$connection->isClosed()) {
            $connection->close();
        }
        $this->initNewRequest();
        $this->restartKeepAliveCounter();
        $this->connection = null;
    }

    public function onHeartBeat(NetworkStreamInterface $connection, $data = null)
    {
        switch ($this->requestPhase) {
            case static::REQUEST_PHASE_KEEP_ALIVE:
                $this->keepAliveTimer--;

                if ($this->keepAliveTimer === 0) {
                    $connection->close();
                }
                break;

            default:
                break;
        }
    }

    public function onMessage(NetworkStreamInterface $connection, string $message)
    {
        $this->requestPhase = static::REQUEST_PHASE_READING;

        if (!$this->headersReceived) {
            $request = $this->parseRequestHeaders($message);
            if (!$request) {
                return;
            }

            $request->setMetadata('remoteAddress', $this->remoteAddress);

            $this->request = $request;
            $this->response->setVersion($this->request->getVersion());
            $this->headersReceived = true;
            $this->validateRequestHeaders($connection);
            $isKeepAliveRequest = $this->keepAliveCount > 0 && $request->getConnectionType() === 'keep-alive';
            $request->setMetadata('isKeepAliveConnection', $isKeepAliveRequest);
        }

        if ($this->headersReceived) {
            $this->decodeRequestBody($message);

            if ($this->isBodyAllowedInRequest($this->request)) {
                $this->parseRequestPostData($this->request);
                $this->parseRequestFileData($this->request);
            }

            if ($this->bodyReceived && $this->headersReceived) {
                $this->requestComplete = true;
            }

            if ($this->requestComplete) {
                $callback = $this->getDispatcher();
                $this->dispatchRequest($connection, $callback);
            }
        }
    }

    /**
     * @param NetworkStreamInterface $connection
     * @param callback $callback
     * @throws Throwable
     */
    protected function dispatchRequest(NetworkStreamInterface $connection, $callback)
    {
        $exception = null;
        $this->connection = $connection;

        try {
            $this->requestPhase = static::REQUEST_PHASE_PROCESSING;
            $this->mapUploadedFiles($this->request);
            ob_start([$this, 'sendResponse'], $this->bufferSize);
            $callback($this->request, $this->response);

            $this->requestPhase = static::REQUEST_PHASE_SENDING;

        } catch (Throwable $exception) {

        }

        ob_end_flush();

        if ($exception) {
            $this->onError($connection, $exception);
        }
    }

    protected function initNewRequest()
    {
        $this->headersSent = false;
        $this->request = null;
        $this->response = new Response();
        $this->headersReceived = false;
        $this->bodyReceived = false;
        $this->requestComplete = false;
        $this->posInRequestBody = 0;
        $this->compressionHandler = null;
        $this->deleteTemporaryFiles();
    }

    protected function validateRequestHeaders(NetworkStreamInterface $connection)
    {
        $this->setHost($this->request, $connection->getLocalAddress());
        //$this->request->setBasePath(sprintf("%s:%d", $this->request->getUri()->getHost(), $this->request->getUri()->getPort()));

        // todo: validate hostname?
        if ($this->request->getVersion() === Request::VERSION_11) {
            // everything's ok, should we send "100 Continue" first?
            $expectHeader = $this->request->getHeaderOverview('Expect', false);
            if ($expectHeader === '100-continue') {
                $connection->write(sprintf("HTTP/%s 100 Continue\r\n\r\n", Request::VERSION_11));
            }
        }
    }

    protected function decodeRequestBody(string & $message)
    {
        if ($this->bodyReceived) {
            return;
        }

        if (!$this->isBodyAllowedInRequest($this->request)) {
            if (isset($message[0])) {
                // method is not allowing to send a body
                throw new InvalidArgumentException("Body not allowed in this request: $message", Response::STATUS_CODE_400);
            }

            $this->requestComplete = true;
            $this->bodyReceived = true;

            return;
        }

        if ($this->getEncodingType($this->request) === static::ENCODING_CHUNKED) {
            $this->decodeChunkedRequestBody($this->request, $message);

            return;
        }

        $this->decodeRegularRequestBody($this->request, $message);
    }

    protected function sendHeaders(string & $buffer)
    {
        $connection = $this->connection;
        $response = $this->response;
        $request = $this->request;
        $responseHeaders = $response->getHeaders();

        $isChunkedResponse = $this->enableCompressionIfSupported($buffer) || !$responseHeaders->has('Content-Length');

        if ($responseHeaders->has('Transfer-Encoding')) {
            $responseHeaders->removeHeader(new TransferEncoding());
        }

        if ($isChunkedResponse) {
            if ($this->request->getVersion() === Request::VERSION_10) {
                // keep-alive should be disabled for HTTP/1.0 and chunked output (btw. Transfer Encoding should not be set for 1.0)
                $request->setMetadata('isKeepAliveConnection', false);
            } else {
                $responseHeaders->addHeader($this->chunkedHeader);
            }
        }

        $response->setMetadata('isChunkedResponse', $isChunkedResponse);
        if ($request->getMetadata('isKeepAliveConnection')) {
            $responseHeaders->addHeader($this->keepAliveHeader);
            $responseHeaders->addHeader($this->keepAliveCounterHeader);
        } else {
            $responseHeaders->addHeader($this->closeHeader);
        }
        $connection->write(
            $response->renderStatusLine() . "\r\n" .
            $responseHeaders->toString() .
            "Date: " . gmdate('D, d M Y H:i:s') . " GMT\r\n" .
            "X-TTL: " . $this->keepAliveCount. "\r\n" .
            "X-PID: " . getmypid(). "\r\n" .
            "\r\n");

        $this->headersSent = true;
    }

    protected function enableCompressionIfSupported(string & $buffer) : bool
    {
        $this->compressionHandler = null;

        if (!function_exists('deflate_init') || !$this->isBodyAllowedInResponse($this->request)) {
            return false;
        }

        $responseHeaders = $this->response->getHeaders();
        $acceptEncoding = $this->request->getHeaderOverview('Accept-Encoding', true);
        $encodingsArray = $acceptEncoding ? explode(",", str_replace(' ', '', $acceptEncoding)) : [];

        if (!in_array('gzip', $encodingsArray)) {
            return false;
        }

        // don't compress already compressed data...
        $fileType = $responseHeaders->has("Content-Type") ?
            str_replace("/", ".", $responseHeaders->get("Content-Type")->getFieldValue()) : $this->request->getUri()->getPath();

        if (preg_match('~\.(?:gif|jpe?g|ico|png|exe|t?gz|zip|bz2|sit|rar|pdf)$~', $fileType)) {
            return false;
        }

        $sizeHeader = $responseHeaders->get("Content-Length");
        if ($sizeHeader) {
            $size = $sizeHeader->getFieldValue();
            if ($size < 4096) {
                return false;
            }
            $responseHeaders->removeHeader($sizeHeader);
        }

        if (!$sizeHeader && !isset($buffer[4096])) {
            return false;
        }

        $this->compressionHandler = deflate_init(ZLIB_ENCODING_GZIP);
        $responseHeaders->addHeader(new ContentEncoding('gzip'));
        $responseHeaders->addHeader(new Vary('Accept'));

        return true;
    }

    public function sendResponse(string $buffer) : string
    {
        $connection = $this->connection;

        if (!$this->headersSent) {
            $this->sendHeaders($buffer);
        }

        $stream = $this->response->getStream();

        if (!is_resource($stream)) {
            $this->sendBody($connection, $buffer);

            return '';
        }

        if ($this->isBodyAllowedInResponse($this->request)) {
            $this->requestPhase = static::REQUEST_PHASE_PROCESSING;
            if ($buffer) {
                $this->sendBody($connection, $buffer);
            }

            while (!feof($stream)) {
                $data = fread($stream, $this->bufferSize);
                $this->sendBody($connection, $data);
            }
            $this->requestPhase = static::REQUEST_PHASE_SENDING;
        }

        $this->sendBody($connection, null);

        $this->response->setStream(null);
        fclose($stream);

        return '';
    }

    protected function sendBody(NetworkStreamInterface $connection, string $buffer = null)
    {
        if ($this->isBodyAllowedInResponse($this->request)) {
            $isChunkedResponse = $this->response->getMetadata('isChunkedResponse');

            if ($isChunkedResponse) {
                if ($this->compressionHandler) {
                    //$buffer = gzcompress($buffer, 1);
                    $buffer = deflate_add($this->compressionHandler, $buffer, $this->requestPhase === static::REQUEST_PHASE_SENDING ? ZLIB_FINISH : ZLIB_NO_FLUSH);
                }

                $bufferSize = strlen($buffer);
                if ($bufferSize > 0) {
                    $buffer = sprintf("%s\r\n%s\r\n", dechex($bufferSize), $buffer);
                }

                if ($this->requestPhase === static::REQUEST_PHASE_SENDING) {
                    $buffer .= "0\r\n\r\n";
                }
            }

            if ($buffer !== null) {
                $this->response->setMetadata('dataSentInBytes', $this->response->getMetadata('dataSentInBytes') + strlen($buffer));
                $connection->write($buffer);
            }
        }

        if ($this->requestPhase === static::REQUEST_PHASE_SENDING) {
            $this->finalizeRequest();

            return;
        }
    }

    protected function finalizeRequest()
    {
        $connection = $this->connection;

        if (is_callable($this->responseHandler)) {
            $callback = $this->responseHandler;
            $callback($this->request, $this->response);
        }

        $this->requestsFinished++;
        try {
            $this->connection->flush();
        } catch (IOException $ex) {
            $this->request->setMetadata('isKeepAliveConnection', false);
        }

        if (!$this->request->getMetadata('isKeepAliveConnection')) {
            $this->onClose($connection);

            return;
        }

        $this->keepAliveCount--;
        $this->initNewRequest();
        $this->restartKeepAliveTimer();
        $this->requestPhase = static::REQUEST_PHASE_KEEP_ALIVE;
    }

    /**
     * @param Request $request
     * @param Throwable $exception
     * @return Response
     * @internal param Response $response
     */
    protected function dispatchError(Request $request, Throwable $exception) : Response
    {
        $statusCode = $exception->getCode() >= Response::STATUS_CODE_400 ? $exception->getCode() : Response::STATUS_CODE_500;

        $response = $this->response;
        $response->setVersion($request->getVersion());

        $response->setStatusCode($statusCode);
        echo $exception->getMessage();

        return $response;
    }

    protected function restartKeepAliveCounter()
    {
        $this->keepAliveCount = static::MAX_KEEP_ALIVE_REQUESTS;
        $this->restartKeepAliveTimer();
    }

    protected function restartKeepAliveTimer()
    {
        $this->keepAliveTimer = 5;
    }

    /**
     * @return int
     */
    public function getNumberOfFinishedRequests() : int
    {
        return $this->requestsFinished;
    }
}