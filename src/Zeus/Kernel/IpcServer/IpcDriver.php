<?php

namespace Zeus\Kernel\IpcServer;

abstract class IpcDriver
{
    const AUDIENCE_ALL = 'aud_all';
    const AUDIENCE_ANY = 'aud_any';
    const AUDIENCE_SERVER = 'aud_srv';
    const AUDIENCE_SELECTED = 'aud_sel';
    const AUDIENCE_AMOUNT = 'aud_num';
    const AUDIENCE_SELF = 'aud_self';

    /**
     * @param $message
     * @param string $audience
     * @param int $number
     * @return $this
     */
    public abstract function send($message, $audience = self::AUDIENCE_ALL, int $number = 0);

    /**
     * @param bool $returnRaw
     * @return mixed[]
     */
    public abstract function readAll($returnRaw = false);
}