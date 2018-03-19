<?php

namespace Zeus\ServerService\Shared\Networking\Service;

use function strpos;
use function substr;

class ReadBuffer
{
    /** @var string */
    private $data = '';

    public function append(string $data)
    {
        $this->data .= $data;
    }

    public function read(int $size = 0) : string
    {
        if ($size === 0) {
            $data = $this->data;
            $this->data = '';

            return $data;
        }

        $data = substr($this->data, 0, $size);
        $this->data = substr($this->data, $size);

        return $data;
    }

    public function peek(int $size = 0) : string
    {
        if ($size === 0) {
            return $this->data;
        }

        return substr($this->data, 0, $size);
    }

    public function skip(int $size)
    {
        $this->data = substr($this->data, $size);
    }

    public function __toString()
    {
        return $this->read();
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