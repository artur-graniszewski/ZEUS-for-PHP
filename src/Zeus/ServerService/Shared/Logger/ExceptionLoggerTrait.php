<?php

namespace Zeus\ServerService\Shared\Logger;

use Throwable;
use Zend\Log\LoggerInterface;

use function get_class;
use function addcslashes;
use function sprintf;

trait ExceptionLoggerTrait
{
    protected function logException(Throwable $exception, LoggerInterface $logger)
    {
        $logger->err(sprintf("%s (%d): %s in %s on line %d",
            get_class($exception),
            $exception->getCode(),
            addcslashes($exception->getMessage(), "\t\n\r\0\x0B"),
            $exception->getFile(),
            $exception->getLine()
        ));
        $logger->err(sprintf("Stack Trace:\n%s", $exception->getTraceAsString()));
    }
}