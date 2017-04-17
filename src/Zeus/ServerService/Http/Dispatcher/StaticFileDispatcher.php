<?php

namespace Zeus\ServerService\Http\Dispatcher;

use Zend\Http\Header\ContentLength;
use Zend\Http\Header\ContentType;
use Zend\Http\Request;
use Zend\Http\Response;
use Zeus\ServerService\Http\MimeType;

class StaticFileDispatcher implements DispatcherInterface
{
    /** @var DispatcherInterface */
    protected $anotherDispatcher;

    /** @var mixed[] */
    protected $config;

    /** @var string */
    protected $publicDirectory;

    /**
     * StaticFileDispatcher constructor.
     * @param mixed[] $config
     * @param DispatcherInterface|null $anotherDispatcher
     */
    public function __construct(array $config, DispatcherInterface $anotherDispatcher = null)
    {
        $this->config = $config;
        $this->anotherDispatcher = $anotherDispatcher;

        $publicDirectory = isset($this->config['public_directory']) ?
            rtrim($this->config['public_directory'], '\/')
            :
            getcwd() . '/public';

        if ($publicDirectory[0] !== '/') {
            $publicDirectory = getcwd() . '/' . $publicDirectory;
        }

        $this->publicDirectory = realpath($publicDirectory . '/');
    }

    /**
     * @param Request $request
     * @param Response $response
     * @return void
     */
    public function dispatch(Request $request, Response $response)
    {
        $path = $request->getUri()->getPath();

        $code = Response::STATUS_CODE_200;

        $fileName = $this->publicDirectory . DIRECTORY_SEPARATOR . $path;
        $realPath = substr(realpath($fileName), 0, strlen($this->publicDirectory));
        if ($realPath && $realPath !== $this->publicDirectory) {
            $response->setStatusCode(Response::STATUS_CODE_400);
            return;
        }

        $blockedFileTypes = isset($this->config['blocked_file_types']) ? implode('|', $this->config['blocked_file_types']) : null;

        if (file_exists($fileName) && !is_dir($fileName)) {
            if ($blockedFileTypes && preg_match('~\.(' . $blockedFileTypes . ')$~', $fileName)) {
                $response->setStatusCode(Response::STATUS_CODE_403);
                return;
            }

            $response->setStatusCode($code);
            $this->addHeadersForFile($response, $fileName);
            if (is_callable([$response, 'setStream'])) {
                $response->setStream(fopen($fileName, "r"));


                return;
            }
            readfile($fileName);

            return;
        }

        $code = is_dir($fileName) ? Response::STATUS_CODE_403 : Response::STATUS_CODE_404;

        if ($this->anotherDispatcher) {
            $this->anotherDispatcher->dispatch($request, $response);

            return;
        }

        $response->setStatusCode($code);
    }

    /**
     * @param Response $response
     * @param string $fileName
     */
    protected function addHeadersForFile(Response $response, $fileName)
    {
        $response->getHeaders()->addHeader(new ContentLength(filesize($fileName)));
        $response->getHeaders()->addHeader(new ContentType(MimeType::getMimeType($fileName)));
    }
}