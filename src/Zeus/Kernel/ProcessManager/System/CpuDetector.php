<?php

namespace Zeus\Kernel\ProcessManager\System;

/**
 * Class CpuDetector
 * @package Zeus\Kernel\ProcessManager\System
 * @internal
 * @codeCoverageIgnore
 */
final class CpuDetector
{
    /** @var int */
    protected static $numberOfCores = 0;

    /**
     * @return int
     */
    public static function getNumberOfCores()
    {
        if (!static::$numberOfCores) {
            static::$numberOfCores = @static::detectNumberOfCores();
        }

        return static::$numberOfCores;
    }

    /**
     * @return int
     */
    protected static function detectNumberOfCores()
    {
        if (is_file('/proc/cpuInfo') && is_readable('/proc/cpuInfo')) {
            $cpuInfo = file_get_contents('/proc/cpuInfo');
            if (preg_match_all('/^processor/m', $cpuInfo, $matches)) {
                return count($matches[0]);
            }
        }

        $cpuCores = 1;

        if ('WIN' == strtoupper(substr(PHP_OS, 0, 3))) {
            $process = @popen('wmic cpu get NumberOfCores', 'rb');
            if (false !== $process) {
                fgets($process);
                $cpuCores = (int) fgets($process);
                pclose($process);
            }

            return $cpuCores;
        }

        $process = @popen('sysctl -a', 'rb');
        if (false !== $process) {
            $output = stream_get_contents($process);
            if (preg_match('/hw.ncpu: (\d+)/', $output, $matches)) {
                $cpuCores = (int) $matches[1][0];
            }
            pclose($process);
        }

        return $cpuCores;
    }
}