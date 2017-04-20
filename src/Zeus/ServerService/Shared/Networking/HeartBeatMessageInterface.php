<?php

namespace Zeus\ServerService\Shared\Networking;

/**
 * Interface HeartBeatMessageInterface
 * @package Zeus\ServerService\Shared\React
 * @internal
 */
interface HeartBeatMessageInterface
{
    public function onHeartBeat(ConnectionInterface $connection, $data = null);
}