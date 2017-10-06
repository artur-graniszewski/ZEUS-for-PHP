<?php

namespace Zeus\Kernel\IpcServer;

use Zeus\Kernel\IpcServer;

abstract class IpcDriver
{
    /**
     * @param $message
     * @param string $audience
     * @param int $number
     * @return $this
     */
    public abstract function send($message, $audience = IpcServer::AUDIENCE_ALL, int $number = 0);

    /**
     * @param bool $returnRaw
     * @return mixed[]
     */
    public abstract function readAll($returnRaw = false);
}