<?php

namespace Zeus\Kernel\Scheduler\MultiProcessingModule\PThreads;

class ThreadBootstrap extends \Thread {
    public $server;
    public $argv;
    public $id;
    /** @var int */
    public $ipcPort;

    public function run()
    {
        global $_SERVER;
        global $argv;
        global $argc;
        $_SERVER = [];
        foreach ($this->server as $type => $value) {
            $_SERVER[$type] = $value;
        }
        $_SERVER['argv'] = (array) $this->argv;
        $_SERVER['argc'] = count($this->argv);

        $argv = $_SERVER['argv'];
        $argc = $_SERVER['argc'];
        $php = '
                    define("ZEUS_THREAD_CONN_PORT", ' . $this->ipcPort . ');
                    define("ZEUS_THREAD_ID", ' . $this->id . ');
                    $SERVER = ' . var_export((array) $_SERVER, true) .';
                    foreach ($SERVER as $type => $value) {
                        $_SERVER[$type] = $value;
                    }

                    unset ($SERVER);
               
                    require_once($_SERVER[\'SCRIPT_NAME\']);
                ?>';

        $this->id = \Thread::getCurrentThreadId();

        eval ($php);
        exit();
    }
}