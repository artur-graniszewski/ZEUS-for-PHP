<?php

namespace Zeus\ServerService\Shared\Networking\Service;

use function strpos;

class ReadBuffer
{
    private $data = '';

    public function append(string $data)
    {
        $this->data .= $data;
    }

    public function getData() : string
    {
        $data = $this->data;
        $this->data = '';

        return $data;
    }

    public function find(string $mark) : int
    {
        $pos = strpos($this->data, $mark);

        if (false === $pos) {
            $pos = -1;
        }

        return $pos;
    }
}