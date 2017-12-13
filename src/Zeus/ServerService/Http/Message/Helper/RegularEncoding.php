<?php

namespace Zeus\ServerService\Http\Message\Helper;

use Zend\Http\Request;
use Zend\Http\Response;

use function ctype_digit;

trait RegularEncoding
{
    private $contentReceived = 0;

    public function decodeRegularRequestBody(Request $request, & $message)
    {
        $expectedBodyLength = $request->getHeaderOverview('Content-Length', false);

        if (false === $expectedBodyLength) {
            $this->bodyReceived = true;
            $this->requestComplete = true;
            $this->contentReceived = 0;

            return;
        }

        if (!ctype_digit($expectedBodyLength) || $expectedBodyLength < 0) {
            throw new \InvalidArgumentException("Invalid or missing Content-Length header: $expectedBodyLength", Response::STATUS_CODE_400);
        }

        $expectedBodyLength = (int) $expectedBodyLength;
        $messageLength = strlen($message);

        if ($this->contentReceived < $expectedBodyLength) {
            // check if message fits into the gap...
            if ($messageLength + $this->contentReceived > $expectedBodyLength) {
                throw new \InvalidArgumentException("Request body is larger than set in the Content-Length header", Response::STATUS_CODE_400);
            }

            $request->setContent($request->getContent() . $message);
            $this->contentReceived += $messageLength;
            $message = '';
        }

        if ($this->contentReceived === $expectedBodyLength) {
            $this->bodyReceived = true;
            $this->requestComplete = true;
            $this->contentReceived = 0;
        }
    }
}