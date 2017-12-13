<?php

namespace Zeus\ServerService\Http\Message\Helper;

use Zend\Http\Request;
use Zend\Http\Response;

use function preg_match;
use function strlen;
use function strpos;
use function substr;
use function hexdec;

trait ChunkedEncoding
{
    private $buffer = null;

    /** @var int */
    private $expectedChunkSize = 0;

    public function clearBuffer()
    {
        unset($this->buffer);
        $this->buffer = null;
        $this->expectedChunkSize = 0;
    }

    public function decodeChunkedRequestBody(Request $request, string & $message)
    {
        $this->buffer .= $message;

        while (true) {
            if ($this->expectedChunkSize === -1) {
                // read the chunk header
                if (0 !== strpos($this->buffer, "\r\n")) {
                    // no chunk yet, read more data
                    return;
                }

                // @todo: support trailers!
                $this->requestComplete = true;
                $this->bodyReceived = true;

                $message = substr($message, 2);
                $this->clearBuffer();

                return;
            }

            if ($this->expectedChunkSize === 0) {
                // read the chunk header
                if (false === strpos($this->buffer, "\r\n")) {
                    // no chunk yet, read more data
                    $message = '';
                    return;
                }

                if (!preg_match("/^([\da-fA-F]+)[^\r\n]*\r\n/", $this->buffer, $matches)) {
                    throw new \InvalidArgumentException("Invalid chunk header", Response::STATUS_CODE_400);
                }

                $headerSize = strpos($this->buffer, "\r\n") + 2;
                $chunkSize = hexdec($matches[1]);
                $this->buffer = substr($this->buffer, $headerSize);

                if ($chunkSize === 0) {
                    // that is a closing chunk, expect \r\n\r\n and stop parsing body
                    $this->expectedChunkSize = -1;
                    continue;
                }
                $this->expectedChunkSize = $chunkSize;
            }

            $bufferSize = strlen($this->buffer);

            // is chunk content complete?
            if ($bufferSize < $this->expectedChunkSize + 2) {
                // no, fetch more data
                $message = '';
                return;
            }

            $messageLeft = $bufferSize - $this->expectedChunkSize + 2;
            $request->setContent($request->getContent() . substr($this->buffer, 0, $this->expectedChunkSize));
            $this->buffer = substr($this->buffer, $this->expectedChunkSize + 2);
            // $this->requestBodySize += $this->expectedChunkSize;

            $message = $messageLeft < strlen($message) ?
                // chunk is part of the current message, so trim it
                substr($message, -$messageLeft)
                :
                // body is already large enough that current chunk is not a part of current message, consume it
                // and continue
                '';

            $this->expectedChunkSize = 0;
        }
    }
}