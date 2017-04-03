<?php

namespace ZeusTest\Helpers;

use Zeus\Controller\ConsoleController;

class ConsoleControllerMock extends ConsoleController
{
    protected $exitCode;

    /**
     * @param int $code
     */
    protected function doExit($code)
    {
        $this->exitCode = $code;
    }

    /**
     * @return int
     */
    public function getExitCode()
    {
        return $this->exitCode;
    }

    /**
     * @param mixed $exitCode
     * @return ConsoleControllerMock
     */
    public function setExitCode($exitCode)
    {
        $this->exitCode = $exitCode;
        return $this;
    }

}