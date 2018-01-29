<?php

namespace Zeus\Kernel\IpcServer;

use Zeus\Kernel\IpcServer;

/**
 * Class IpcDriver
 * @package Zeus\Kernel\IpcServer
 * @internal
 */
interface IpcDriver
{
    /**
     * @param mixed $message
     * @param string $audience
     * @param int $number
     */
    public function send($message, string $audience = IpcServer::AUDIENCE_ALL, int $number = 0);

    /**
     * @param bool $returnRaw
     * @return mixed[]
     */
    public function readAll(bool $returnRaw = false) : array;
}