<?php

namespace Zeus\ServerService\Shared\Logger;

interface LoggerInterface extends \Zend\Log\LoggerInterface
{
    /**
     * Add a message as a log entry
     *
     * @param  int $priority
     * @param  mixed $message
     * @param  mixed[]|\Traversable $extra
     * @return \Zend\Log\LoggerInterface
     */
    public function log($priority, $message, $extra = []);
}