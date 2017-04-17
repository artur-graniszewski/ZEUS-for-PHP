<?php

namespace Zeus\ServerService\Http\Dispatcher;

use Zend\Http\Request;
use Zend\Http\Response;

interface DispatcherInterface
{
    /**
     * DispatcherInterface constructor.
     * @param mixed[] $config
     * @param DispatcherInterface|null $anotherDispatcher
     */
    public function __construct(array $config, DispatcherInterface $anotherDispatcher = null);

    /**
     * @param Request $request
     * @param Response $response
     */
    public function dispatch(Request $request, Response $response);
}