<?php

namespace Zeus\Kernel\ProcessManager\MultiProcessingModule;

use Zend\Log\LoggerInterface;

abstract class AbstractModule
{
    private $logger;

    /**
     * @param LoggerInterface $logger
     * @return $this
     */
    public function setLogger(LoggerInterface $logger)
    {
        $this->logger = $logger;

        return $this;
    }

    /**
     * @return LoggerInterface
     */
    public function getLogger() : LoggerInterface
    {
        return $this->logger;
    }
}