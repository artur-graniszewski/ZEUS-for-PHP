<?php

namespace Zeus\Kernel\Scheduler\Exception;

class SchedulerException extends \RuntimeException
{
    const SCHEDULER_NOT_STARTED = 1;
    const LOCK_FILE_ERROR = 2;
    const SCHEDULER_NOT_RUNNING = 4;
    const INVALID_CONFIGURATION = 8;
    const SCHEDULER_TERMINATED = 16;
    const WORKER_NOT_STARTED = 64;
    const CLI_MODE_REQUIRED = 128;
}