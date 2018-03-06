<?php

namespace Zeus\ServerService\Shared\Networking\Service;

class WorkerIPC
{
    /** @var int */
    private $uid;

    /** @var string */
    private $address;

    public function getAddress() : string
    {
        return $this->address;
    }

    public function getUid() : int
    {
        return $this->uid;
    }

    public function __construct(int $uid, string $address)
    {
        $this->uid = $uid;
        $this->address = $address;
    }
}