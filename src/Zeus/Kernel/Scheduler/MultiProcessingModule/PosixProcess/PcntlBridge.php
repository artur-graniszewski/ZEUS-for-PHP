<?php

namespace Zeus\Kernel\Scheduler\MultiProcessingModule\PosixProcess;

/**
 * Class PcntlBridge
 * @package Zeus\Kernel\Scheduler\MultiProcessingModule\PosixProcess
 * @codeCoverageIgnore
 */
class PcntlBridge implements PosixProcessBridgeInterface
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
     * @throws \Exception
     * @internal
     */
    public function isSupported()
    {
        $className = basename(str_replace('\\', '/', static::class));

        if (!$this->isPcntlExtensionLoaded()) {
            throw new \RuntimeException(sprintf("PCNTL extension is required by %s but disabled in PHP",
                    $className
                )
            );
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
            throw new \RuntimeException(sprintf("Following functions are required by %s but disabled in PHP: %s",
                    $className,
                    implode(", ", $missingFunctions)
                )
            );
        }
    }

    /**
     * @return bool
     */
    protected function isPcntlExtensionLoaded()
    {
        return extension_loaded('pcntl');
    }
}