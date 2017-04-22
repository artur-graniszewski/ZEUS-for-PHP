<?php

namespace Zeus\ServerService\Shared\React;

/**
 * Interface HeartBeatMessageInterface
 * @package Zeus\ServerService\Shared\React
 * @internal
 */
interface HeartBeatMessageInterface
{
    public function onHeartBeat(ConnectionInterface $connection, $data = null);
}