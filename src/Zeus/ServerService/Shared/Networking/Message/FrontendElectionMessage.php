<?php

namespace Zeus\ServerService\Shared\Networking\Message;

class FrontendElectionMessage
{
    private $frontendsAmount = 0;

    public function __construct(int $frontendsAmount)
    {
        $this->frontendsAmount = $frontendsAmount;
    }

    public function getTargetFrontendsAmount() : int
    {
        return $this->frontendsAmount;
    }
}