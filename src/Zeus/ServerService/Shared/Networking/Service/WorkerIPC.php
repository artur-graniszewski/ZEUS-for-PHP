<?php

namespace Zeus\ServerService\Shared\Networking\Service;

use Zeus\IO\Stream\NetworkStreamInterface;

class WorkerIPC
{
    /** @var int */
    private $uid;

    /** @var string */
    private $address;

    /** @var NetworkStreamInterface */
    private $stream;

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

    public function setStream(NetworkStreamInterface $stream)
    {
        $this->stream = $stream;
    }

    public function getStream() : NetworkStreamInterface
    {
        return $this->stream;
    }
}