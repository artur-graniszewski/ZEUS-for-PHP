<?php

namespace Zeus\ServerService\Http\Message\Helper;

use Zeus\ServerService\Http\Message\Request;

use function strpos;
use function is_array;
use function parse_str;
use function substr;
use function strlen;
use function array_merge;

trait PostData
{
    protected function parseRequestPostData(Request $request)
    {
        $body = $request->getContent();

        if (!$body || $request->getHeaderOverview('Content-Type', true) !== 'application/x-www-form-urlencoded') {
            return;
        }

        $requestPost = $request->getPost();

        while (false !== ($pos = strpos($body, "&", $this->posInRequestBody)) || $this->bodyReceived) {
            $paramsLength = $pos === false ? strlen($body) : $pos;
            $postParameter = substr($body, $this->posInRequestBody, $paramsLength - $this->posInRequestBody);
            $postArray = [];
            parse_str($postParameter, $postArray);
            $paramName = key($postArray);
            if (is_array($postArray[$paramName])) {
                $postArray[$paramName] = array_merge((array) $requestPost->get($paramName), $postArray[$paramName]);
            }

            $requestPost->set($paramName, $postArray[$paramName]);

            $this->posInRequestBody = $pos + 1;

            if ($pos === false) {
                break;
            }
        }
    }
}