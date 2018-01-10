<?php

namespace Zeus\Kernel\System;

/**
 * Class Runtime
 * @package Zeus\Kernel\Scheduler\System
 * @internal
 * @codeCoverageIgnore
 */
class Runtime
{
    /** @var int */
    protected static $processorAmount = 0;

    /**
     * @return int
     */
    public static function getNumberOfProcessors() : int
    {
        if (!static::$processorAmount) {
            static::$processorAmount = @static::detectNumberOfCores();
        }

        return static::$processorAmount;
    }

    /**
     * @return int
     */
    protected static function detectNumberOfCores() : int
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
}