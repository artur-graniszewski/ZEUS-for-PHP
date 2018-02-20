<?php

namespace Zeus\Controller;

use InvalidArgumentException;
use Throwable;
use Zend\Log\LoggerInterface;
use Zend\Mvc\Controller\AbstractActionController;
use Zend\Stdlib\RequestInterface;
use Zeus\Kernel\System\Runtime;
use Zeus\ServerService\Manager;
use Zend\Console\Request as ConsoleRequest;

abstract class AbstractController extends AbstractActionController
{
    /** @var mixed[] */
    private $config;

    /** @var Manager */
    private $manager;

    /** @var LoggerInterface */
    private $logger;

    /**
     * @param mixed[] $config
     */
    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function checkIfConsole(RequestInterface $request)
    {
        if (!$request instanceof ConsoleRequest) {
            throw new InvalidArgumentException(sprintf(
                '%s can only dispatch requests in a console environment',
                get_called_class()
            ));
        }
    }

    public function setServiceManager(Manager $manager)
    {
        $this->manager = $manager;
    }

    public function getServiceManager() : Manager
    {
        return $this->manager;
    }

    public function getLogger() : LoggerInterface
    {
        return $this->logger;
    }

    public function setLogger(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    protected function handleException(Throwable $exception)
    {
        $this->getLogger()->err(sprintf("%s (%d): %s in %s on line %d",
            get_class($exception),
            $exception->getCode(),
            addcslashes($exception->getMessage(), "\t\n\r\0\x0B"),
            $exception->getFile(),
            $exception->getLine()
        ));
        $this->getLogger()->err(sprintf("Stack Trace:\n%s", $exception->getTraceAsString()));
        Runtime::exit($exception->getCode() > 0 ? $exception->getCode() : 500);
    }
}