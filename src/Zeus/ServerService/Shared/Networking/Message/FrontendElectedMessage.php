<?php

namespace Zeus\ServerService\Shared\Networking\Message;

class FrontendElectedMessage
{
    private $ipcAddress = '';

    public function __construct(string $ipcAddress)
    {
        $this->ipcAddress = $ipcAddress;
    }

    public function getIpcAddress() : string
    {
        return $this->ipcAddress;
    }
}