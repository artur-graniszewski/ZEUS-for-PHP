<?php

namespace Zeus\Kernel\Scheduler\MultiProcessingModule\PosixProcess;

interface PcntlBridgeInterface
{
    public function pcntlSignalDispatch() : bool;

    /**
     * @param int $action
     * @param int[] $signals
     * @param mixed $oldSet
     * @return bool
     */
    public function pcntlSigprocmask(int $action, array $signals, &$oldSet = null);

    public function pcntlWait(int &$status, int $options) : int;

    /**
     * @param int $signal
     * @param callable|int $handler
     * @param bool $restartSysCalls
     * @return bool
     */
    public function pcntlSignal(int $signal, $handler, bool $restartSysCalls = true) : bool;

    /**
     * @return int
     */
    public function pcntlFork() : int;

    public function posixGetPpid() : int;

    public function posixSetSid() : int;

    public function posixKill(int $pid, int $signal) : bool;

    public function isSupported() : bool;

    public function pcntlAsyncSignals(bool $enable = null);
}