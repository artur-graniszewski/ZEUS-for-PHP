<?php

namespace Zeus\Kernel\Scheduler\MultiProcessingModule\PosixProcess;

use function posix_setsid;
use function pcntl_sigprocmask;
use function pcntl_wait;
use function pcntl_signal;
use function pcntl_fork;
use function posix_getppid;
use function posix_kill;
use function is_callable;
use function extension_loaded;

/**
 * Class PcntlBridge
 * @package Zeus\Kernel\Scheduler\MultiProcessingModule\PosixProcess
 * @codeCoverageIgnore
 */
class PcntlBridge implements PcntlBridgeInterface
{
    public function posixSetsid() : int
    {
        return posix_setsid();
    }

    public function pcntlSignalDispatch() : bool
    {
        return pcntl_signal_dispatch();
    }

    /**
     * @param int $action
     * @param int[] $signals
     * @param mixed $oldSet
     * @return bool
     */
    public function pcntlSigprocmask(int $action, array $signals, &$oldSet = null) : bool
    {
        return pcntl_sigprocmask($action, $signals, $oldSet);
    }

    public function pcntlWait(int &$status, int $options) : int
    {
        return pcntl_wait($status, $options);
    }

    /**
     * @param int $signal
     * @param callable $handler
     * @param bool $restartSysCalls
     * @return bool
     */
    public function pcntlSignal(int $signal, $handler, bool $restartSysCalls = true) : bool
    {
        return pcntl_signal($signal, $handler, $restartSysCalls);
    }

    public function pcntlFork() : int
    {
        return pcntl_fork();
    }

    public function posixGetPpid() : int
    {
        return posix_getppid();
    }

    public function posixKill(int $pid, int $signal) : bool
    {
        return posix_kill($pid, $signal);
    }

    /**
     * @internal
     */
    public function isSupported() : bool
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

    protected function isPcntlExtensionLoaded() : bool
    {
        return extension_loaded('pcntl');
    }
}