<?php

namespace Zeus\Util;

class UnitConverter
{
    /**
     * @param int $milliseconds
     * @return float Seconds
     */
    public static function convertMillisecondsToSeconds(int $milliseconds) : float
    {
        return $milliseconds > 0 ? $milliseconds / 1000 : 0.0;
    }

    /**
     * @param int $milliseconds
     * @return int Microseconds
     */
    public static function convertMillisecondsToMicroseconds(int $milliseconds) : int
    {
        return $milliseconds > 0 ? $milliseconds * 1000 : 0;
    }
}