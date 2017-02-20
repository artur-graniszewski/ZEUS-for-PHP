<?php

namespace Zeus\ServerService\Http\Message;

use Zeus\ServerService\Http\Message\Helper\ChunkedEncoding;
use Zeus\ServerService\Http\Message\Helper\Header;
use Zeus\ServerService\Http\Message\Helper\PostData;
use Zeus\ServerService\Http\Message\Helper\RegularEncoding;
use Zeus\ServerService\Http\Message\Helper\FileUpload;
use Zeus\ServerService\Shared\React\HeartBeatMessageInterface;
use Zeus\ServerService\Shared\React\MessageComponentInterface;
use Zeus\ServerService\Shared\React\ConnectionInterface;
use Zend\Http\Header\Connection;
use Zend\Http\Header\ContentEncoding;
use Zend\Http\Header\ContentLength;
use Zend\Http\Header\TransferEncoding;
use Zend\Http\Header\Vary;
use Zend\Http\Response;
use Zend\Validator\Hostname as HostnameValidator;

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

    /** @var int */
    protected $requestPhase = self::REQUEST_PHASE_IDLE;

    /** @var int */
    protected $bufferSize = 0;

    /** @var callable */
    protected $errorHandler;

    /** @var Callback */
    protected $dispatcher;

    /** @var bool */
    protected $headersSent = false;

    /** @var int */
    protected $keepAliveCount = 100;

    /** @var int */
    protected $keepAliveTimer = 5;

    /** @var TransferEncoding */
    protected $chunkedHeader;

    /** @var Connection */
    protected $closeConnectionHeader;

    /** @var Connection */
    protected $keepAliveConnectionHeader;

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
    protected $cursorPositionInRequestPostBody = 0;

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
        $this->closeConnectionHeader = (new Connection())->setValue("close");
        $this->keepAliveConnectionHeader = (new Connection())->setValue("keep-alive; timeout=" . $this->keepAliveTimer);
        $this->dispatcher = $dispatcher;
        $this->initNewRequest();
        $this->restartKeepAliveCounter();
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

    /**
     * @param ConnectionInterface $connection
     */
    public function onOpen(ConnectionInterface $connection)
    {
        $this->restartKeepAliveCounter();
        $this->requestPhase = static::REQUEST_PHASE_KEEP_ALIVE;
    }

    /**
     * @param ConnectionInterface $connection
     * @param \Exception $exception
     */
    public function onError(ConnectionInterface $connection, $exception)
    {
        if (!$connection->isWritable()) {
            $this->onClose($connection);

            return;//throw $exception;
        }

        if (!$this->request) {
            $this->request = new Request();
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

        throw $exception;
    }

    /**
     * @param ConnectionInterface $connection
     */
    public function onClose(ConnectionInterface $connection)
    {
        $this->requestPhase = static::REQUEST_PHASE_IDLE;
        $connection->end();
        $this->initNewRequest();
        $this->restartKeepAliveCounter();
    }

    /**
     * @param ConnectionInterface $connection
     * @param null $data
     */
    public function onHeartBeat(ConnectionInterface $connection, $data = null)
    {
        switch ($this->requestPhase) {
            case static::REQUEST_PHASE_KEEP_ALIVE:
                $this->keepAliveTimer--;

                if ($this->keepAliveTimer === 0) {
                    $connection->end();
                }
                break;

            default:
                break;
        }
    }

    /**
     * @param ConnectionInterface $connection
     * @param string $message
     */
    public function onMessage(ConnectionInterface $connection, $message)
    {
        $this->requestPhase = static::REQUEST_PHASE_READING;

        if (!$this->headersReceived) {
            if ($request = $this->parseRequestHeaders($message)) {
                $this->request = $request;
                $this->headersReceived = true;
                $this->validateRequestHeaders($connection);
            }
        }

        if ($this->headersReceived) {
            $this->decodeRequestBody($connection, $message);

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
     * @param ConnectionInterface $connection
     * @param callback $callback
     * @return $this
     */
    protected function dispatchRequest(ConnectionInterface $connection, $callback)
    {
        $this->requestPhase = static::REQUEST_PHASE_PROCESSING;

        ob_start(function($buffer) use ($connection) { $this->sendResponse($connection, $buffer); }, $this->bufferSize);
        $this->mapUploadedFiles($this->request);
        $this->response = $callback($this->request);

        $this->requestPhase = static::REQUEST_PHASE_SENDING;
        ob_end_flush();

        return $this;
    }

    /**
     * @return $this
     */
    protected function initNewRequest()
    {
        $this->headersSent = false;
        $this->request = null;
        $this->response = new Response();
        $this->headersReceived = false;
        $this->bodyReceived = false;
        $this->requestComplete = false;
        $this->cursorPositionInRequestPostBody = 0;
        $this->deleteTemporaryFiles();

        return $this;
    }

    /**
     * @param ConnectionInterface $connection
     * @return $this
     */
    protected function validateRequestHeaders(ConnectionInterface $connection)
    {
        $this->setHost($this->request, $connection->getServerAddress());
        //$this->request->setBasePath(sprintf("%s:%d", $this->request->getUri()->getHost(), $this->request->getUri()->getPort()));

        // todo: validate hostname?
        if ($this->request->getVersion() === Request::VERSION_11) {
            // everything's ok, should we send "100 Continue" first?
            $expectHeader = $this->request->getHeaderOverview('Expect');
            if ($expectHeader === '100-continue') {
                $connection->write(sprintf("HTTP/%s 100 Continue\r\n\r\n", $this->request->getVersion()));
            }
        }

        return $this;
    }

    /**
     * @param ConnectionInterface $from
     * @param string $message
     * @return $this
     */
    protected function decodeRequestBody(ConnectionInterface $from, & $message)
    {
        if ($this->bodyReceived || false === $this->headersReceived) {
            return $this;
        }

        if (!$this->isBodyAllowedInRequest($this->request)) {
            if (!isset($message[0])) {
                $this->requestComplete = true;
                $this->bodyReceived = true;
                return $this;
            }
            // method is not allowing to send a body
            throw new \InvalidArgumentException("Body not allowed in this request", Response::STATUS_CODE_400);
        }

        if ($this->getEncodingType($this->request) === $this::ENCODING_CHUNKED) {
            $this->decodeChunkedRequestBody($this->request, $message);
        } else {
            $this->decodeRegularRequestBody($this->request, $message);
        }

        return $this;
    }

    /**
     * @param ConnectionInterface $from
     * @param string $buffer
     * @return $this
     */
    protected function sendHeaders(ConnectionInterface $from, & $buffer)
    {
        $response = $this->response;
        $request = $this->request;
        $responseHeaders = $response->getHeaders();
        $requestVersion = $this->request->getVersion();
        $response->setVersion($requestVersion);

        $transferEncoding = $responseHeaders->get('Transfer-Encoding');

        $isChunkedResponse = ($transferEncoding && $transferEncoding->getFieldValue() === $this::ENCODING_CHUNKED);
        $requestPhase = $this->requestPhase;

        $isKeepAliveConnection = $this->keepAliveCount > 0 && $request->getConnectionType() === 'keep-alive';

        // keep-alive should be disabled for HTTP/1.0 and chunked output (btw. Transfer Encoding should not be set for 1.0)
        // we can also disable chunked response if buffer contained entire response body
        if ($requestVersion === Request::VERSION_10 || $requestPhase === static::REQUEST_PHASE_SENDING) {
            $isChunkedResponse = false;
            if ($transferEncoding) {
                $responseHeaders->removeHeader(new TransferEncoding());
            }

            $acceptEncoding = $request->getHeaderOverview('Accept-Encoding', true);
            $encodingsArray = $acceptEncoding ? explode(",", str_replace(' ', '', $acceptEncoding)) : [];
            if ($requestPhase === static::REQUEST_PHASE_SENDING && isset($buffer[8192]) && in_array('gzip', $encodingsArray)) {
                $buffer = substr(gzcompress($buffer, 1, ZLIB_ENCODING_GZIP), 0, -4);
                $responseHeaders->addHeader(new ContentEncoding('gzip'));
                $responseHeaders->addHeader(new Vary('Accept'));
            }
            $responseHeaders->addHeader(new ContentLength(strlen($buffer)));
        } else {
            if (!$isChunkedResponse && $this->isBodyAllowedInResponse($request) && !$responseHeaders->has('Content-Length')) {
                // is this a chunked encoding? valid only for HTTP 1.1+
                $responseHeaders->addHeader($this->chunkedHeader);

                $isChunkedResponse = true;
            }
        }

        $responseHeaders->addHeader($isKeepAliveConnection? $this->keepAliveConnectionHeader : $this->closeConnectionHeader);

        $from->write(
            $response->renderStatusLine() . "\r\n" .
            $responseHeaders->toString() .
            "Date: " . gmdate('D, d M Y H:i:s') . " GMT\r\n" .
            "\r\n");

        $this->headersSent = true;

        $response->setMetadata('isChunkedResponse', $isChunkedResponse);
        $request->setMetadata('isKeepAliveConnection', $isKeepAliveConnection);

        return $this;
    }

    /**
     * @param ConnectionInterface $from
     * @param string $buffer
     * @return string
     */
    protected function sendResponse(ConnectionInterface $from, $buffer)
    {
        $isChunkedResponse = $this->response->getMetadata('isChunkedResponse');

        if (!$this->headersSent) {
            $this->sendHeaders($from, $buffer);
        }

        if ($this->isBodyAllowedInResponse($this->request)) {
            if ($isChunkedResponse) {
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
                $from->write($buffer);
            }
        }

        $this->request->setMetadata('remoteAddress', $from->getRemoteAddress());
        if ($this->requestPhase !== static::REQUEST_PHASE_SENDING) {
            //trigger_error("ESCAPE", E_USER_ERROR);
            return '';
        }

        if (is_callable($this->responseHandler)) {
            $callback = $this->responseHandler;
            $callback($this->request, $this->response);
        }

        if ($this->request->getMetadata('isKeepAliveConnection') && $this->keepAliveCount > 0) {
            $this->requestsFinished++;
            $this->keepAliveCount--;
            $this->initNewRequest();
            $this->restartKeepAliveTimer();
            $this->requestPhase = static::REQUEST_PHASE_KEEP_ALIVE;
        } else {
            $from->end();
            $this->initNewRequest();
            $this->restartKeepAliveCounter();
            $this->requestPhase = static::REQUEST_PHASE_IDLE;
        }

        return '';
    }

    /**
     * @param Request $request
     * @param Response $response
     * @param \Exception|\Error $exception
     * @return Response
     */
    protected function dispatchError(Request $request, $exception)
    {
        $statusCode = $exception->getCode() >= Response::STATUS_CODE_400 ? $exception->getCode() : Response::STATUS_CODE_500;

        $response = $this->response;
        $response->setVersion($request->getVersion());

        $response->setStatusCode($statusCode);
        echo $exception->getMessage();

        return $response;
    }

    /**
     * @return $this
     */
    protected function restartKeepAliveCounter()
    {
        $this->keepAliveCount = 100;
        $this->restartKeepAliveTimer();

        return $this;
    }

    /**
     * @return $this
     */
    protected function restartKeepAliveTimer()
    {
        $this->keepAliveTimer = 5;

        return $this;
    }

    /**
     * @return int
     */
    public function getNumberOfFinishedRequests()
    {
        return $this->requestsFinished;
    }
}