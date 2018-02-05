<?php

namespace Zeus\Kernel\Scheduler\MultiProcessingModule\ProcessOpen;

use function function_exists;
use function proc_open;
use function proc_get_status;
use function defined;

class ProcessOpenBridge implements ProcessOpenBridgeInterface
{
    private $stdOut = 'php://stdout';

    private $stdErr = 'php://stderr';

    public function isSupported() : bool
    {
        return function_exists('proc_open') && function_exists('proc_get_status') && !defined("HHVM_VERSION");
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

    public function setStdOut(string $path)
    {
        $this->stdOut = $path;
    }

    public function setStdErr(string $path)
    {
        $this->stdErr = $path;
    }

    public function getStdOut() : string
    {
        return $this->stdOut;
    }

    public function getStdErr() : string
    {
        return $this->stdErr;
    }
}