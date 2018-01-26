<?php

namespace Zeus\ServerService\Shared\Networking;

use Zeus\IO\Stream\NetworkStreamInterface;

/**
 * Interface HeartBeatMessageInterface
 * @internal
 */
interface HeartBeatMessageInterface
{
    public function onHeartBeat(NetworkStreamInterface $connection, $data = null);
}