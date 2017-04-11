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
        $command = $message[0];
        if ($command !== '@') {
            $message = substr($message, 1);
            if ($command === '0') {
                $message = stripslashes($message);
            }

            $message = unserialize($message);
            return $message;
        }

        return substr($message, 1);
    }

    /**
     * @param mixed $message
     * @return string
     */
    protected function packMessage($message)
    {
        $newLine = false;

        if (!is_object($message) && !is_array($message) && ($newLine = (false === strpos($message, "\0")))) {
            return '@' . $message;
        }

        $message = serialize($message);
        $message = $newLine ? '0' . addslashes($message) : '!' . $message;

        return $message;
    }
}