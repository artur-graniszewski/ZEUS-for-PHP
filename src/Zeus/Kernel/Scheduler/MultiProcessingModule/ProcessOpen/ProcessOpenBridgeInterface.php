<?php

namespace Zeus\Kernel\Scheduler\MultiProcessingModule\ProcessOpen;

interface ProcessOpenBridgeInterface
{
    public function isSupported() : bool;

    public function procOpen($command, $descriptors, array & $pipes, $cwd, $env, $options);

    public function getProcStatus($resource);

    public function setStdOut(string $path);

    public function setStdErr(string $path);

    /**
     * @return resource
     * @internal
     */
    public function getStdOut() : string;

    /**
     * @return resource
     * @internal
     */
    public function getStdErr() : string;
}