<?php

namespace Zeus\ServerService\Shared\Networking\Message;

class FrontendElectionMessage
{
    private $frontendsAmount;

    public function __construct(int $frontendsAmount)
    {
        $this->frontendsAmount;
    }

    public function getTargetFrontendsAmount() : int
    {
        return $this->frontendsAmount;
    }
}