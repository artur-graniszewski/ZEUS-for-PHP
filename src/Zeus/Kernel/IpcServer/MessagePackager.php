<?php

namespace Zeus\Kernel\IpcServer;

use function stripslashes;
use function addslashes;
use function unserialize;
use function serialize;
use function is_object;
use function strpos;
use function is_array;

trait MessagePackager
{
    /**
     * @param string $message
     * @return mixed
     */
    protected function unpackMessage(string $message)
    {
        $command = $message[0];
        $message = substr($message, 1);
        if ($command !== '@') {
            if ($command === '0') {
                $message = stripslashes($message);
            }

            $message = unserialize($message);
            return $message;
        }

        return $message;
    }

    /**
     * @param mixed $message
     * @return string
     */
    protected function packMessage($message) : string
    {
        $noNull = false;

        if (!is_object($message) && !is_array($message) && ($noNull = (false === strpos($message, "\0")))) {
            return '@' . $message;
        }

        $message = serialize($message);
        $message = $noNull ? '!' . $message : '0' . addslashes($message);

        return $message;
    }
}