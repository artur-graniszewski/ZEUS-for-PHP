<?php

namespace Zeus\ServerService\Shared\Networking;

class LeaderElectedMessage
{
    private $ipcAddress = '';

    public function __construct($ipcAddress)
    {
        $this->ipcAddress = $ipcAddress;
    }

    public function getIpcAddress() : string
    {
        return $this->ipcAddress;
    }
}