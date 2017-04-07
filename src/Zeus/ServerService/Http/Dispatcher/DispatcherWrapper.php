<?php

namespace Zeus\ServerService\Http\Dispatcher;

use Zend\Http\Request;
use Zend\Http\Response;
use Zeus\Kernel\ProcessManager\Process;

class DispatcherWrapper implements DispatcherInterface
{
    /** @var DispatcherInterface */
    protected $anotherDispatcher;

    /** @var mixed[] */
    protected $config;

    /**
     * StaticFileDispatcher constructor.
     * @param mixed[] $config
     * @param DispatcherInterface|null $anotherDispatcher
     */
    public function __construct(array $config, DispatcherInterface $anotherDispatcher = null)
    {
        $this->config = $config;
        $this->anotherDispatcher = $anotherDispatcher;

        if (!$anotherDispatcher) {
            throw new \LogicException(__CLASS__ . " dispatcher is just a wrapper and must chain another dispatcher");
        }
    }

    /**
     * @param Request $httpRequest
     * @return Response
     */
    public function dispatch(Request $httpRequest, Response $httpResponse)
    {
        /** @var Process $process */
        //$process = $this->config['service']->getProcess();
        //$process->setRunning($httpRequest->getUriString());
        $result = $this->anotherDispatcher->dispatch($httpRequest, $httpResponse);
        //$process->setWaiting($httpRequest->getUriString());

        return $result;
    }
}