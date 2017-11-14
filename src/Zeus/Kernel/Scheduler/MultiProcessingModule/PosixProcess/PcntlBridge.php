<?php

namespace Zeus\Kernel\Scheduler\MultiProcessingModule\PosixProcess;

/**
 * Class PcntlBridge
 * @package Zeus\Kernel\Scheduler\MultiProcessingModule\PosixProcess
 * @codeCoverageIgnore
 */
class PcntlBridge implements PcntlBridgeInterface
{
    /**
     * @return int
     */
    public function posixSetsid()
    {
        return posix_setsid();
    }

    /**
     * @return bool
     */
    public function pcntlSignalDispatch()
    {
        return pcntl_signal_dispatch();
    }

    /**
     * @param int $action
     * @param int[] $signals
     * @param mixed $oldSet
     * @return bool
     */
    public function pcntlSigprocmask($action, array $signals, &$oldSet = null)
    {
        return pcntl_sigprocmask($action, $signals, $oldSet);
    }

    /**
     * @param int $status
     * @param int $options
     * @return int
     */
    public function pcntlWait(&$status, $options)
    {
        return pcntl_wait($status, $options);
    }

    /**
     * @param int $signal
     * @param callable $handler
     * @param bool $restartSysCalls
     * @return bool
     */
    public function pcntlSignal($signal, $handler, $restartSysCalls = true)
    {
        return pcntl_signal($signal, $handler, $restartSysCalls);
    }

    /**
     * @return int
     */
    public function pcntlFork()
    {
        return pcntl_fork();
    }

    /**
     * @return int
     */
    public function posixGetPpid()
    {
        return posix_getppid();
    }

    /**
     * @param int $pid
     * @param int $signal
     * @return bool
     */
    public function posixKill($pid, $signal)
    {
        return posix_kill($pid, $signal);
    }

    /**
     * @internal
     */
    public function isSupported()
    {
        if (!$this->isPcntlExtensionLoaded()) {
            return false;
        }

        $requiredFunctions = [
            'pcntl_signal',
            'pcntl_sigprocmask',
            'pcntl_signal_dispatch',
            'pcntl_wifexited',
            'pcntl_wait',
            'posix_getppid',
            'posix_kill'
        ];

        $missingFunctions = [];

        foreach ($requiredFunctions as $function) {
            if (!is_callable($function)) {
                $missingFunctions[] = $function;
            }
        }

        if ($missingFunctions) {
            return false;
        }

        return true;
    }

    /**
     * @return bool
     */
    protected function isPcntlExtensionLoaded()
    {
        return extension_loaded('pcntl');
    }
}