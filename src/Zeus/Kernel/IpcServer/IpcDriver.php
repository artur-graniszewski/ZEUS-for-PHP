<?php

namespace Zeus\Kernel\IpcServer;

use Zeus\Kernel\IpcServer;

/**
 * Class IpcDriver
 * @package Zeus\Kernel\IpcServer
 * @internal
 */
abstract class IpcDriver
{
    /**
     * @param mixed $message
     * @param string $audience
     * @param int $number
     */
    public abstract function send($message, string $audience = IpcServer::AUDIENCE_ALL, int $number = 0);

    /**
     * @param bool $returnRaw
     * @return mixed[]
     */
    public abstract function readAll(bool $returnRaw = false) : array;
}