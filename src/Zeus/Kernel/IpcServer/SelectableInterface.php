<?php

namespace Zeus\Kernel\IpcServer;

interface SelectableInterface
{
    public function setSoTimeout(int $timeout);
}