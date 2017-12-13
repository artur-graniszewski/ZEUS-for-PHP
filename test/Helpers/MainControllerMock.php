<?php

namespace ZeusTest\Helpers;

use Zeus\Controller\MainController;

class MainControllerMock extends MainController
{
    protected $exitCode;

    /**
     * @param int $code
     */
    protected function doExit(int $code)
    {
        $this->exitCode = $code;
    }

    /**
     * @return int
     */
    public function getExitCode() : int
    {
        return $this->exitCode;
    }

    /**
     * @param int $exitCode
     */
    public function setExitCode(int $exitCode)
    {
        $this->exitCode = $exitCode;
    }

}