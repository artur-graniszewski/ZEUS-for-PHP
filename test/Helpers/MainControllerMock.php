<?php

namespace ZeusTest\Helpers;

use Zeus\Controller\MainController;

class MainControllerMock extends MainController
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
     * @return MainControllerMock
     */
    public function setExitCode($exitCode)
    {
        $this->exitCode = $exitCode;
        return $this;
    }

}