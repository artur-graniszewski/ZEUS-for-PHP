<?php

namespace Zeus\ServerService\Http\Message\Helper;

use Zend\Http\Header\GenericHeader;
use Zend\Http\Response;
use Zeus\ServerService\Http\Message\Request;

trait FileUpload
{
    /** @var mixed[] */
    protected $currentFormDataInfo = [];

    /** @var mixed[] */
    protected $requestFilesInfo = [];

    /** @var bool */
    protected $formDataHeadersReceived = false;

    /** @var bool */
    protected $formDataReceived = false;

    /**
     * @param Request $request
     * @param string $boundaryLine
     * @param int $pos
     * @return string|null
     */
    protected function checkBodyHeaders(Request $request, $boundaryLine, $pos)
    {
        // initialize data block
        if (!isset($this->currentFormDataInfo['tmp_name'])) {
            // file not opened yet, check the boundary
            if (substr($request->getContent(), 0, $pos) !== $boundaryLine) {
                throw new \InvalidArgumentException("Boundary missing in multipart data", Response::STATUS_CODE_400);
            }

            $tmpFileName = tempnam(sys_get_temp_dir(), 'php_upload_');
            $file = fopen($tmpFileName, 'w+');
            $tmpFileName = stream_get_meta_data($file)['uri'];
            $this->currentFormDataInfo['handle'] = $file;
            $this->currentFormDataInfo['tmp_name'] = $tmpFileName;
            $this->currentFormDataInfo['type'] = 'text/plain';
            $this->currentFormDataInfo['error'] = UPLOAD_ERR_OK;
            $request->setContent(substr($request->getContent(), $pos + 2));

            return null;
        }

        // check headers
        // is this the last header?
        if ($pos === 0) {
            $this->formDataHeadersReceived = true;
            $request->setContent(substr($request->getContent(), 2));

            return null;
        }

        $headerLine = substr($request->getContent(), 0, $pos);

        // check the header...
        $header = GenericHeader::fromString($headerLine);
        $headerFieldName = strtolower($header->getFieldName());
        switch ($headerFieldName) {
            case 'content-type':
                $this->currentFormDataInfo['type'] = $header->getFieldValue();
                break;

            case 'content-disposition':
                $headerValue = $header->getFieldValue();
                $headerParts = explode(';', $headerValue);

                foreach ($headerParts as $part) {
                    $this->checkContentDisposition($part);
                }
                break;

            default:
                // @todo: validate other headers
                break;
        }

        return $headerLine;
    }

    /**
     * @param string $headerPart
     * @return $this
     */
    protected function checkContentDisposition($headerPart)
    {
        $part = trim($headerPart);
        if ($part === 'form-data') {

            return $this;
        }

        if (preg_match('~^filename="([^"]+)"$~i', $part, $matches)) {
            $this->currentFormDataInfo['name'] = $matches[1];

            return $this;
        }

        if (preg_match('~^name="([^"]+)"$~i', $part, $matches)) {
            $this->currentFormDataInfo['form_name'] = $matches[1];

            return $this;
        }

        throw new \InvalidArgumentException("Unknown content-disposition parameter: $part", Response::STATUS_CODE_400);
    }

    /**
     * @param Request $request
     * @return $this
     */
    protected function parseRequestFileData(Request $request)
    {
        $body = $request->getContent();
        $boundaryLine = $this->getMultipartDataBoundary($request);

        if (!$body || $this->formDataReceived || !$boundaryLine) {
            return $this;
        }

        $boundaryClosingLine = $boundaryLine . '--';

        while (false !== ($pos = strpos($request->getContent(), "\r\n"))) {
            if (!$this->formDataHeadersReceived) {
                $headerLine = $this->checkBodyHeaders($request, $boundaryLine, $pos);

                if (!$headerLine) {
                    continue;
                }

                $request->setContent(substr($request->getContent(), strlen($headerLine) + 2));

                continue;
            }

            if ($this->formDataHeadersReceived) {
                $bodyLine = substr($request->getContent(), 0, $pos);
                // check if there's a boundary in the buffer
                if ($bodyLine === $boundaryLine || $bodyLine === $boundaryClosingLine) {
                    $this->registerUploadedFile($request);

                    if ($bodyLine !== $boundaryClosingLine) {
                        continue;
                    }

                    $this->formDataReceived = true;

                    return $this;
                }

                fwrite($this->currentFormDataInfo['handle'], $bodyLine);
                $request->setContent(substr($request->getContent(), $pos + 2));

                continue;
            }
        }

        if (!$this->formDataHeadersReceived) {
            // headers are incomplete, fetch more data...
            return $this;
        }

        // check if there's a boundary in the buffer
        if ($request->getContent() === $boundaryLine . '--') {
            $this->registerUploadedFile($request);

            $request->setContent('');

            $this->formDataReceived = true;
            // @todo: validate if ending boundary line is exactly at the end of a request
            return $this;
        }

        // no new line found, check if its a buffer that can be sent to disk (or if it may contain part of the boundary)
        $body = $request->getContent();
        if ($body !== substr($boundaryLine, 0, strlen($body)) && $body !== substr($boundaryClosingLine, 0, strlen($body))) {
            if (substr($body, -1) === "\r") {
                // the new line at the end of a buffer may preceed the boundary string, don't write anything yet
                return $this;
            }
            fwrite($this->currentFormDataInfo['handle'], $body);
            $request->setContent('');
        }

        return $this;

        // there may be a boundary hidden here, fetch more data
    }

    /**
     * @param Request $request
     * @return $this
     */
    protected function registerUploadedFile(Request $request)
    {
        $this->formDataHeadersReceived = false;
        $this->currentFormDataInfo['size'] = filesize($this->currentFormDataInfo['tmp_name']);

        fclose($this->currentFormDataInfo['handle']);
        unset($this->currentFormDataInfo['handle']);

        if (!isset($this->currentFormDataInfo['name'])) {
            // its not a file, just a POST variable
            // for now, read it into memory
            // @todo: handle big data (stream from file to variable?)
            $request->getPost()->set($this->currentFormDataInfo['form_name'], file_get_contents($this->currentFormDataInfo['tmp_name']));
            unlink($this->currentFormDataInfo['tmp_name']);
            $this->currentFormDataInfo = [];

            return $this;
        }

        $this->requestFilesInfo[$this->currentFormDataInfo['form_name']][] = $this->currentFormDataInfo;
        $this->currentFormDataInfo = [];

        return $this;
    }

    /**
     * @param Request $request
     * @return $this
     */
    protected function mapUploadedFiles(Request $request)
    {
        $request->getFiles()->fromArray($this->requestFilesInfo);
        $this->requestFilesInfo = [];
        $this->formDataHeadersReceived = false;

        return $this;
    }

    /**
     * @return $this
     */
    protected function deleteTemporaryFiles()
    {
        foreach ($this->requestFilesInfo as $formData) {
            foreach ($formData as $file) {
                if (file_exists($file['tmp_name'])) {
                    unlink($file['tmp_name']);
                }
            }
        }

        return $this;
    }

    /**
     * @param Request $request
     * @return string
     */
    protected function getMultipartDataBoundary(Request $request)
    {
        $contentType = $request->getHeaderOverview('Content-Type', true);

        if (preg_match('~^multipart/form-data; boundary=([^\r\n]+)$~i', $contentType, $matches)) {
            return '--' . $matches[1];
        }

        // @todo: validate the above header
    }
}