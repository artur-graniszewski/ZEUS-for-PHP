<?php

namespace Zeus\Kernel\IpcServer\Adapter\Helper;

trait MessagePackager
{
    /**
     * @param string $message
     * @return mixed
     */
    protected function unpackMessage($message)
    {
        if ($message[0] === '!') {
            return unserialize(base64_decode(substr($message, 1)));
        }

        return substr($message, 1, strlen($message) - 2);
    }

    /**
     * @param mixed $message
     * @return string
     */
    protected function packMessage($message)
    {
        if (!is_object($message) && !is_array($message) && false === strpos($message, "\n")) {
            $message = '@' . $message;
        } else {
            $message = '!' . base64_encode(serialize($message));
        }

        return $message;
    }
}