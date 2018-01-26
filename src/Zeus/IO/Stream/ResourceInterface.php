<?php

namespace Zeus\IO\Stream;

interface ResourceInterface
{
    public function getResource();

    public function getResourceId() : int;
}