<?php

namespace Zeus\ServerService\Shared\Networking\Service;

interface ServiceInterface
{
    public function startService(string $workerHost, int $backlog, int $port = -1);

    public function stopService();
}