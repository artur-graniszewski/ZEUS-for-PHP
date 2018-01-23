<?php

namespace Zeus\ServerService\Shared\Networking\Message;

class FrontendElectionMessage
{
    private $frontendsAmount;

    private $ipcAddress = '';

    public function __construct(string $ipcAddress, int $frontendsAmount)
    {
        $this->ipcAddress = $ipcAddress;
        $this->frontendsAmount;
    }

    public function getTargetFrontendsAmount() : int
    {
        return $this->frontendsAmount;
    }

    public function getIpcAddress() : string
    {
        return $this->ipcAddress;
    }
}