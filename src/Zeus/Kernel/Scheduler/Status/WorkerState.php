<?php

namespace Zeus\Kernel\Scheduler\Status;

use function function_exists;
use function getrusage;
use function ceil;
use function min;
use function max;
use function getmypid;
use function microtime;

/**
 * Current status of the worker.
 */
class WorkerState
{
    /**
     * Worker is waiting.
     */
    const WAITING = 1;

    /**
     * Worker is busy right now.
     */
    const RUNNING = 2;

    /**
     * Worker is terminated.
     */
    const TERMINATED = 4;

    /**
     * Worker is exiting on its own.
     */
    const EXITING = 8;

    /**
     * Worker is not initialized yet
     */
    const NEW = 0;

    /**
     * PID of the process.
     *
     * @var int
     */
    private $processId;

    /**
     * Worker status code.
     *
     * @var int
     */
    private $code;

    /**
     * Timestamp of the last status change.
     *
     * @var float
     */
    private $time;

    /** @var float */
    private $startTime = 0;

    /** @var float */
    private $currentUserCpuTime = 0;

    /** @var float */
    private $currentSysCpuTime = 0;

    /** @var int */
    private $tasksFinished = 0;

    /** @var int */
    private $tasksPerSecond = 0;

    /** @var int */
    private $tasksInThisSecond = 0;

    /** @var string */
    private $serviceName = null;

    /** @var float */
    private $cpuUsage = null;

    /** @var string */
    private $statusDescription = '';

    /** @var int */
    private $threadId = 1;

    /** @var int */
    private $uid = 0;

    /** @var bool */
    private $isLastTask = false;

    public function __construct(string $serviceName, int $status = self::NEW)
    {
        $this->startTime = microtime(true);
        $this->code = $status;
        $this->time = $this->startTime;
        $this->serviceName = $serviceName;
    }

    public function getThreadId() : int
    {
        return $this->threadId;
    }

    public function setThreadId(int $threadId)
    {
        $this->threadId = $threadId;
    }

    public function getUid() : int
    {
        return $this->uid;
    }

    public function setUid(int $uid)
    {
        $this->uid = $uid;
    }

    public function getNumberOfTasksPerSecond() : float
    {
        return $this->tasksPerSecond;
    }

    public function isLastTask() : bool
    {
        return $this->isLastTask;
    }

    public function setIsLastTask(bool $isLastTask)
    {
        $this->isLastTask = $isLastTask;
    }

    public function toArray() : array
    {
        return [
            'code' => $this->code,
            'uid' => $this->getUid(),
            'process_id' => $this->getProcessId(),
            'thread_id' => $this->getThreadId(),
            'requests_finished' => $this->tasksFinished,
            'requests_per_second' => $this->tasksPerSecond,
            'time' => $this->time,
            'service_name' => $this->serviceName,
            'cpu_usage' => $this->getCpuUsage(),
            'status_description' => $this->statusDescription,
        ];
    }

    /**
     * @param mixed[] $array
     * @return static
     */
    public static function fromArray(array $array)
    {
        $status = new static($array['service_name'], $array['code']);
        $status->setTime($array['time']);
        $status->tasksFinished = $array['requests_finished'];
        $status->tasksPerSecond = $array['requests_per_second'];
        $status->cpuUsage = $array['cpu_usage'];
        $status->processId = $array['process_id'];
        $status->uid = $array['uid'];
        $status->threadId = $array['thread_id'];
        $status->statusDescription = $array['status_description'];

        return $status;
    }

    public function getStatusDescription() : string
    {
        return $this->statusDescription;
    }

    public function setStatusDescription(string $statusDescription = null)
    {
        $this->statusDescription = $statusDescription;
    }

    public function setTime(float $time)
    {
        $this->time = $time;
    }

    public function getTime() : float
    {
        return $this->time;
    }

    public function getProcessId() : int
    {
        return $this->processId ? $this->processId : getmypid();
    }

    public function setProcessId(int $processId)
    {
        $this->processId = $processId;
    }

    public function setCode(int $status)
    {
        $this->code = $status;
    }

    public function getCode() : int
    {
        return $this->code;
    }

    public function isIdle() : bool
    {
        return $this->code === WorkerState::WAITING;
    }

    public function isExiting() : bool
    {
        return $this->code === WorkerState::EXITING || $this->code === WorkerState::TERMINATED;
    }

    public function updateStatus()
    {
        $this->incrementNumberOfFinishedTasks(0);
        $this->updateCurrentCpuTime();
    }

    public function incrementNumberOfFinishedTasks(int $amount = 1)
    {
        $now = microtime(true);
        if (ceil($this->time) !== ceil($now)) {
            $this->time = $now;
            $this->tasksPerSecond = $this->tasksInThisSecond;
            $this->tasksInThisSecond = 0;
        }

        $this->tasksInThisSecond += $amount;
        $this->tasksFinished += $amount;
    }

    public function getNumberOfFinishedTasks() : int
    {
        return $this->tasksFinished;
    }

    protected function updateCurrentCpuTime()
    {
        $usage = [
            "ru_stime.tv_sec" => 0,
            "ru_utime.tv_sec" => 0,
            "ru_stime.tv_usec" => 0,
            "ru_utime.tv_usec" => 0,
        ];

        if (function_exists('getrusage')) {
            $usage = getrusage();
        }

        $this->currentSysCpuTime = $usage["ru_stime.tv_sec"] * 1e6 + $usage["ru_stime.tv_usec"];
        $this->currentUserCpuTime = $usage["ru_utime.tv_sec"] * 1e6 + $usage["ru_utime.tv_usec"];
    }

    public function getCurrentSystemCpuTime() : float
    {
        return $this->currentSysCpuTime;
    }

    public function getCurrentUserCpuTime() : float
    {
        return $this->currentUserCpuTime;
    }

    public function getCpuUsage() : float
    {
        if (isset($this->cpuUsage)) {
            return $this->cpuUsage;
        }

        $uptime = max($this->getUptime(), 0.000000001) * 1e6;

        $cpuTime = $this->getCurrentSystemCpuTime() + $this->getCurrentUserCpuTime();

        $cpuUsage = min($cpuTime / $uptime, 1) * 100;

        return $cpuUsage;
    }

    public function getUptime() : float
    {
        return microtime(true) - $this->getStartTime();
    }

    public function getStartTime() : float
    {
        return $this->startTime;
    }

    public function getServiceName() : string
    {
        return $this->serviceName;
    }

    public function setRunning(string $statusDescription = '')
    {
        $this->setTime(time());
        $this->setCode(WorkerState::RUNNING);
        $this->setStatusDescription($statusDescription);
    }

    public function setWaiting(string $statusDescription = '')
    {
        $this->setTime(time());
        $this->setCode(WorkerState::WAITING);
        $this->setStatusDescription($statusDescription);
    }
}