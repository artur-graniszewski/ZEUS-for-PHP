<?php

namespace ZeusTest\Helpers;

use Zeus\Controller\ZeusController;

class ZeusControllerMock extends ZeusController
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
     * @return ZeusControllerMock
     */
    public function setExitCode($exitCode)
    {
        $this->exitCode = $exitCode;
        return $this;
    }

}