<?php

namespace ZeusTest\Helpers;
use Zeus\Kernel\Scheduler\MultiProcessingModule\PosixProcess\PosixProcessBridgeInterface;

/**
 */
class PcntlMockBridge implements PosixProcessBridgeInterface
{
    protected $executionLog = [];
    protected $pcntlWaitPids = [];
    protected $forkResult;
    protected $posixPppid;
    protected $signalDispatch;
    protected $signalHandlers;
    protected $isSupported = true;

    /**
     * @return mixed[]
     */
    public function getExecutionLog()
    {
        return $this->executionLog;
    }

    /**
     * @param mixed[] $executionLog
     */
    public function setExecutionLog(array $executionLog)
    {
        $this->executionLog = $executionLog;
    }

    /**
     * @return int
     */
    public function posixSetsid()
    {
        $this->executionLog[] = [__METHOD__, func_get_args()];

        return 1;
    }

    /**
     * @return bool
     */
    public function pcntlSignalDispatch()
    {
        $this->executionLog[] = [__METHOD__, func_get_args()];

        if ($this->signalDispatch && isset($this->signalHandlers[$this->signalDispatch])) {
            $signal = $this->signalDispatch;
            $this->signalDispatch = null;
            call_user_func($this->signalHandlers[$signal], $this->signalDispatch);
        }

        return true;
    }

    /**
     * @param int $signal
     */
    public function setSignal($signal)
    {
        $this->signalDispatch = $signal;
    }

    /**
     * @param int $action
     * @param int[] $signals
     * @param mixed $oldSet
     * @return bool
     */
    public function pcntlSigprocmask($action, array $signals, &$oldSet = null)
    {
        $this->executionLog[] = [__METHOD__, func_get_args()];

        return true;
    }

    /**
     * @param int $status
     * @param int $options
     * @return int
     */
    public function pcntlWait(&$status, $options)
    {
        $this->executionLog[] = [__METHOD__, func_get_args()];

        if (count($this->pcntlWaitPids) > 0) {
            return array_shift($this->pcntlWaitPids);
        }

        return -1;
    }

    /**
     * @param int[] $pids
     */
    public function setPcntlWaitPids(array $pids)
    {
        $this->pcntlWaitPids = $pids;
    }

    /**
     * @param int $signal
     * @param callable $handler
     * @param bool $restartSysCalls
     * @return bool
     */
    public function pcntlSignal($signal, $handler, $restartSysCalls = true)
    {
        $this->executionLog[] = [__METHOD__, func_get_args()];
        $this->signalHandlers[$signal] = $handler;

        return true;
    }

    /**
     * @return int
     */
    public function pcntlFork()
    {
        $this->executionLog[] = [__METHOD__, func_get_args()];

        return $this->forkResult;
    }

    /**
     * @param $forkResult
     */
    public function setForkResult($forkResult)
    {
        $this->forkResult = $forkResult;
    }

    /**
     * @param $ppid
     */
    public function setPpid($ppid)
    {
        $this->posixPppid = $ppid;
    }

    /**
     * @return int
     */
    public function posixGetPpid()
    {
        $this->executionLog[] = [__METHOD__, func_get_args()];

        return $this->posixPppid ? $this->posixPppid : getmypid();
    }

    /**
     * @param int $pid
     * @param int $signal
     * @return bool
     */
    public function posixKill($pid, $signal)
    {
        $this->executionLog[] = [__METHOD__, func_get_args()];

        return true;
    }

    /**
     * @throws \Exception
     * @internal
     */
    public function isSupported()
    {
        $this->executionLog[] = [__METHOD__, func_get_args()];

        if (!$this->isSupported) {
            $className = basename(str_replace('\\', '/', static::class));

            throw new \RuntimeException(sprintf("PCNTL extension is required by %s but disabled in PHP",
                    $className
                )
            );
        }
    }

    /**
     * @param bool $isSupported
     */
    public function setIsSupported($isSupported)
    {
        $this->isSupported = $isSupported;
    }
}