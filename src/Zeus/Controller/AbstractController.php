<?php

namespace Zeus\Controller;

use InvalidArgumentException;
use Zend\Log\LoggerAwareTrait;
use Zend\Mvc\Controller\AbstractActionController;
use Zend\Stdlib\RequestInterface;
use Zeus\ServerService\Manager;
use Zend\Console\Request as ConsoleRequest;

use function get_called_class;

abstract class AbstractController extends AbstractActionController
{
    use LoggerAwareTrait;

    /** @var mixed[] */
    private $config;

    /** @var Manager */
    private $manager;

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
}