<?php

namespace Zeus\Kernel\ProcessManager\Status;

/**
 * Current status of the worker.
 */
class WorkerState
{
    /**
     * Process is waiting.
     */
    const WAITING = 1;

    /**
     * Process is busy right now.
     */
    const RUNNING = 2;

    /**
     * Process is terminated.
     */
    const TERMINATED = 4;

    /**
     * Process is exiting on its own.
     */
    const EXITING = 8;

    /**
     * PID of the process.
     *
     * @var int
     */
    protected $processId;

    /**
     * Process status code.
     *
     * @var int
     */
    protected $code;

    /**
     * Timestamp of the last status change.
     *
     * @var float
     */
    protected $time;

    /** @var float */
    protected $startTime = 0;

    /** @var float */
    protected $currentUserCpuTime = 0;

    /** @var float */
    protected $currentSysCpuTime = 0;

    /** @var int */
    protected $tasksFinished = 0;

    /** @var int */
    protected $tasksPerSecond = 0;

    /** @var int */
    protected $tasksInThisSecond = 0;

    /** @var string */
    protected $serviceName = null;

    /** @var float */
    protected $cpuUsage = null;

    /** @var string */
    protected $statusDescription = '';

    /** @var int */
    protected $threadId = 1;

    /**
     * TaskStatus constructor.
     * @param string $serviceName
     * @param int $status
     */
    public function __construct(string $serviceName, int $status = self::WAITING)
    {
        $this->startTime = microtime(true);
        $this->code = $status;
        $this->time = $this->startTime;
        $this->serviceName = $serviceName;
    }

    /**
     * @return int
     */
    public function getThreadId() : int
    {
        return $this->threadId;
    }

    /**
     * @param int $threadId
     * @return $this
     */
    public function setThreadId(int $threadId)
    {
        $this->threadId = $threadId;

        return $this;
    }

    /**
     * @return int
     */
    public function getNumberOfTasksPerSecond()
    {
        return $this->tasksPerSecond;
    }

    public function toArray()
    {
        return [
            'code' => $this->code,
            'uid' => $this->getProcessId(),
            'threadId' => $this->getThreadId(),
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
        $status->processId = $array['uid'];
        $status->statusDescription = $array['status_description'];

        return $status;
    }

    /**
     * @return string
     */
    public function getStatusDescription()
    {
        return $this->statusDescription;
    }

    /**
     * @param string $statusDescription
     * @return $this
     */
    public function setStatusDescription(string $statusDescription = null)
    {
        $this->statusDescription = $statusDescription;

        return $this;
    }

    /**
     * @param float $time
     * @return $this
     */
    public function setTime(float $time)
    {
        $this->time = $time;

        return $this;
    }

    /**
     * @return int
     */
    public function getTime()
    {
        return $this->time;
    }

    /**
     * @return int
     */
    public function getProcessId() : int
    {
        return $this->processId ? $this->processId : getmypid();
    }

    /**
     * @param int $processId
     * @return $this
     */
    public function setProcessId(int $processId)
    {
        $this->processId = $processId;

        return $this;
    }

    /**
     * @param int $status
     * @return $this
     */
    public function setCode(int $status)
    {
        $this->code = $status;

        return $this;
    }

    /**
     * @return int
     */
    public function getCode() : int
    {
        return $this->code;
    }

    /**
     * @param mixed[] $array
     * @return bool
     */
    public static function isIdle(array $array)
    {
        return $array['code'] === WorkerState::WAITING;
    }

    /**
     * @param mixed[] $array
     * @return bool
     */
    public static function isExiting(array $array)
    {
        return $array['code'] === WorkerState::EXITING || $array['code'] === WorkerState::TERMINATED;
    }

    /**
     * @return $this
     */
    public function updateStatus()
    {
        $this->incrementNumberOfFinishedTasks(0);
        $this->updateCurrentCpuTime();

        return $this;
    }

    /**
     * @param int $amount
     * @return $this
     */
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

        return $this;
    }

    /**
     * @return int
     */
    public function getNumberOfFinishedTasks() : int
    {
        return $this->tasksFinished;
    }

    /**
     * @return $this
     */
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

        return $this;
    }

    /**
     * @return float
     */
    public function getCurrentSystemCpuTime() : float
    {
        return $this->currentSysCpuTime;
    }

    /**
     * @return float
     */
    public function getCurrentUserCpuTime() : float
    {
        return $this->currentUserCpuTime;
    }

    /**
     * @return float
     */
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

    /**
     * @return float
     */
    public function getUptime() : float
    {
        return microtime(true) - $this->getStartTime();
    }

    /**
     * @return float
     */
    public function getStartTime() : float
    {
        return $this->startTime;
    }

    /**
     * @return string
     */
    public function getServiceName() : string
    {
        return $this->serviceName;
    }
}