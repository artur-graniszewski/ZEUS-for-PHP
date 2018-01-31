<?php

namespace Zeus\Kernel\Scheduler\MultiProcessingModule\ProcessOpen;

class ProcessOpenBridge implements ProcessOpenBridgeInterface
{
    private $stdOut;

    private $stdErr;

    public function isSupported() : bool
    {
        return function_exists('proc_open') && function_exists('proc_get_status');
    }

    public function procOpen($command, $descriptors, array & $pipes, $cwd, $env, $options)
    {
        $result = proc_open($command, $descriptors, $pipes, $cwd, $env, $options);

        return $result;
    }

    public function getProcStatus($resource)
    {
        return proc_get_status($resource);
    }

    public function setStdOut($resource)
    {
        $this->stdOut = $resource;
    }

    public function setStdErr($resource)
    {
        $this->stdErr = $resource;
    }

    /**
     * @return resource
     * @internal
     */
    public function getStdOut()
    {
        return $this->stdOut;
    }

    /**
     * @return resource
     * @internal
     */
    public function getStdErr()
    {
        return $this->stdErr;
    }
}