<?php

namespace ZeusTest\Helpers;

use Exception;
use RuntimeException;
use Zeus\Kernel\Scheduler\MultiProcessingModule\PThreads\ThreadTrait;
use Zeus\Kernel\Scheduler\MultiProcessingModule\PThreads\ThreadWrapperInterface;

class PosixThreadWrapperMock implements ThreadWrapperInterface
{
    use ThreadTrait {
        run as protected runOriginal;
    }

    private $state = 0;
    private static $isTerminated = false;

    const NOTHING = (0);
    const STARTED = (1<<0);
    const RUNNING = (1<<1);
    const JOINED = (1<<2);
    const ERROR = (1<<3);

    public function isStarted()
    {
        return $this->state & self::STARTED;
    }

    public function isJoined()
    {
        return $this->state & self::JOINED;
    }

    public function kill()
    {
        $this->state |= self::ERROR;

        return true;
    }

    public static function getCurrentThreadId()
    {
        return 1;
    }
    public function getThreadId()
    {
        return 1;
    }

    public function start()
    {
        if ($this->state & self::STARTED) {
            throw new RuntimeException();
        }

        $this->state |= self::STARTED;
        $this->state |= self::RUNNING;

        try {
            $this->run();
        } catch(Exception $t) {
            $this->state |= self::ERROR;
        }

        $this->state &= ~self::RUNNING;

        return true;
    }

    public function join()
    {
        if ($this->state & self::JOINED) {
            throw new RuntimeException();
        }

        $this->state |= self::JOINED;

        return true;
    }

    private function run()
    {
        $this->runOriginal();
    }

    public function isTerminated()
    {
        return static::$isTerminated;
    }

    public static function setIsTerminated(bool $isTerminated)
    {
        static::$isTerminated = $isTerminated;
    }
}