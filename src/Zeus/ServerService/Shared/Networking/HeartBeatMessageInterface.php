<?php

namespace Zeus\ServerService\Shared\Networking;
use Zeus\Kernel\Networking\ConnectionInterface;

/**
 * Interface HeartBeatMessageInterface
 * @internal
 */
interface HeartBeatMessageInterface
{
    public function onHeartBeat(ConnectionInterface $connection, $data = null);
}