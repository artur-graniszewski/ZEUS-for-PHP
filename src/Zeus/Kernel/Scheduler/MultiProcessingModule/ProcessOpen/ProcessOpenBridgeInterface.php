<?php

namespace Zeus\Kernel\Scheduler\MultiProcessingModule\ProcessOpen;

interface ProcessOpenBridgeInterface
{
    public function isSupported() : bool;

    public function procOpen($command, $descriptors, array & $pipes, $cwd, $env, $options);

    public function getProcStatus($resource);

    public function setStdOut($resource);

    public function setStdErr($resource);

    /**
     * @return resource
     * @internal
     */
    public function getStdOut();

    /**
     * @return resource
     * @internal
     */
    public function getStdErr();
}