<?php

namespace Zeus\Kernel\System;

use TypeError;
use Closure;

use function set_exception_handler;
use function is_null;
use function is_callable;
use function is_file;
use function is_readable;
use function file_get_contents;
use function preg_match_all;
use function count;
use function substr;
use function strtoupper;
use function popen;
use function stream_get_contents;
use function fgets;
use function pclose;
use function preg_match;
use function function_exists;
use function call_user_func;
use function gc_enabled;
use function gc_enable;
use function gc_mem_caches;
use function gc_collect_cycles;
use function gc_disable;


/**
 * Class Runtime
 * @package Zeus\Kernel\Scheduler\System
 * @internal
 * @codeCoverageIgnore
 */
class Runtime
{
    private static $exitCallback;

    /** @var int */
    private static $processorAmount = 0;

    public static function init()
    {
        if (function_exists('pcntl_async_signals') && is_callable('pcntl_async_signals')) {
            pcntl_async_signals(true);
        }

        if (is_callable('pcntl_signal')) {
            $callback = function(int $signal, $siginfo) {
                static::exit($signal, true);
            };
            pcntl_signal(SIGTERM, $callback);
            pcntl_signal(SIGINT, $callback);
            pcntl_signal(SIGTSTP, $callback);
        }
    }

    public static function getNumberOfProcessors() : int
    {
        if (!static::$processorAmount) {
            static::$processorAmount = @static::detectNumberOfCores();
        }

        return static::$processorAmount;
    }

    private static function detectNumberOfCores() : int
    {
        if (is_file('/proc/cpuinfo') && is_readable('/proc/cpuinfo')) {
            $cpuInfo = file_get_contents('/proc/cpuinfo');
            if (preg_match_all('/^processor/m', $cpuInfo, $matches)) {
                return count($matches[0]);
            }
        }

        $cpuCores = 1;

        if ('WIN' == strtoupper(substr(PHP_OS, 0, 3))) {
            $process = popen('wmic cpu get NumberOfCores', 'rb');
            if (false !== $process) {
                fgets($process);
                $cpuCores = (int) fgets($process);
                pclose($process);
            }

            return $cpuCores;
        }

        $process = popen('sysctl -a', 'rb');
        if (false !== $process) {
            $output = stream_get_contents($process);
            if (preg_match('/hw.ncpu: (\d+)/', $output, $matches)) {
                $cpuCores = (int) $matches[1][0];
            }
            pclose($process);
        }

        return $cpuCores;
    }

    public static function exit(int $code, bool $isSignal = false)
    {
        if (!is_null(static::$exitCallback) && call_user_func(static::$exitCallback, $code, $isSignal)) {
            return;
        }

        exit($code);
    }

    public static function setShutdownHook($callback)
    {
        if (is_callable($callback) || is_null($callback) || $callback instanceof Closure) {
            static::$exitCallback = $callback;
            return;
        }

        throw new TypeError("Invalid callback");
    }

    public static function getShutdownHook()
    {
        return static::$exitCallback;
    }

    public static function setUncaughtExceptionHandler($callback)
    {
        if (is_callable($callback) || is_null($callback) || $callback instanceof Closure) {
            set_exception_handler($callback);
            return;
        }
    }

    public static function runGarbageCollector()
    {
        $enabled = gc_enabled();
        if ($enabled) {
            return;
        }

        gc_enable();
        if (function_exists('gc_mem_caches')) {
            // @codeCoverageIgnoreStart
            gc_mem_caches();
            // @codeCoverageIgnoreEnd
        }
        gc_collect_cycles();

        // @codeCoverageIgnoreStart
        gc_disable();
        // @codeCoverageIgnoreEnd
    }
}