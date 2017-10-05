<?php

namespace Zeus\Kernel\Scheduler\MultiProcessingModule\PosixProcess;

interface PosixProcessBridgeInterface
{
    /**
     * @return bool
     */
    public function pcntlSignalDispatch();

    /**
     * @param int $action
     * @param int[] $signals
     * @param mixed $oldSet
     * @return bool
     */
    public function pcntlSigprocmask($action, array $signals, &$oldSet = null);

    /**
     * @param int $status
     * @param int $options
     * @return int
     */
    public function pcntlWait(&$status, $options);

    /**
     * @param int $signal
     * @param callable|int $handler
     * @param bool $restartSysCalls
     * @return bool
     */
    public function pcntlSignal($signal, $handler, $restartSysCalls = true);

    /**
     * @return int
     */
    public function pcntlFork();

    /**
     * @return int
     */
    public function posixGetPpid();

    /**
     * @return int
     */
    public function posixSetSid();

    /**
     * @param int $pid
     * @param int $signal
     * @return bool
     */
    public function posixKill($pid, $signal);

    public function isSupported();
}