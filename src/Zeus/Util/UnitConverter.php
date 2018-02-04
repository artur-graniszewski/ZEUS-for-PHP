<?php

namespace Zeus\Util;

class UnitConverter
{
    /**
     * @param float $milliseconds
     * @return float Seconds
     */
    public static function convertMillisecondsToSeconds(float $milliseconds) : float
    {
        return $milliseconds > 0 ? $milliseconds / 1000 : 0.0;
    }

    /**
     * @param float $milliseconds
     * @return float Microseconds
     */
    public static function convertMillisecondsToMicroseconds(float $milliseconds) : float
    {
        return $milliseconds > 0 ? $milliseconds * 1000 : 0.0;
    }

    public static function convertMicrosecondsToMilliseconds(float $microseconds) : float
    {
        return $microseconds > 0 ? $microseconds / 1000 : 0.0;
    }
}