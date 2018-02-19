<?php

namespace Zeus\Kernel\Scheduler\MultiProcessingModule\PThreads;


trait ThreadTrait
{
    /** @var mixed[] */
    private $serverVariables;

    /** @var mixed[] */
    private $argv;

    /** @var int */
    private $workerId;

    /** @var string */
    private $ipcAddress;

    public function setWorkerId(int $id)
    {
        $this->workerId = $id;
    }

    public function getWorkerId() : int
    {
        return $this->workerId;
    }

    public function setIpcAddress(string $address)
    {
        $this->ipcAddress = $address;
    }

    public function getIpcAddress() : string
    {
        return $this->ipcAddress;
    }

    public function setServerVariables(array $variables)
    {
        $this->serverVariables = $variables;
    }

    public function setApplicationArguments(array $args)
    {
        $this->argv = $args;
    }

    public function getServerVariables() : array
    {
        return $this->serverVariables ? (array) $this->serverVariables : [];
    }

    public function getApplicationArguments() : array
    {
        return $this->argv ? (array) $this->argv : [];
    }

    protected function initThreadEnvironment()
    {

    }

    public function run()
    {
        global $_SERVER;
        global $argv;
        global $argc;

        $_SERVER = [];
        foreach ($this->getServerVariables() as $type => $value) {
            $_SERVER[$type] = $value;
        }

        $_SERVER['argv'] = $this->getApplicationArguments();
        $_SERVER['argc'] = count($_SERVER['argv']);

        $argv = $_SERVER['argv'];
        $argc = $_SERVER['argc'];
        $php = '
                    define("ZEUS_THREAD_IPC_ADDRESS", "' . addcslashes($this->getIpcAddress(), '"') . '");
                    define("ZEUS_THREAD_ID", ' . $this->getWorkerId() . ');
                    $SERVER = ' . var_export((array) $_SERVER, true) .';
                    foreach ($SERVER as $type => $value) {
                        $_SERVER[$type] = $value;
                    }

                    unset ($SERVER);
               
                    require_once($_SERVER[\'SCRIPT_NAME\']);
                ?>';

        eval ($php);
        $this->exit();
    }
}