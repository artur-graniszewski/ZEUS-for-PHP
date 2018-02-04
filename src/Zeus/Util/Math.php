<?php

namespace Zeus\Util;

class Math
{
    /**
     * Find greatest common divisor using Euclid's algorithm
     *
     * Number of arguments may be variable...
     * @param int $a
     * @param int $b ... int $x
     * @return int
     */
    public static function gcd(int $a, int $b) : int
    {
        $args = func_get_args();

        $result = array_shift($args);
        foreach ($args as $arg) {
            $result = static::gcdPair($result, $arg);
        }

        return $result;
    }

    private static function gcdPair(int $a, int $b) : int
    {
        while ($b > 0) {
            $tmp = $b;
            $b = $a % $b; // % is remainder
            $a = $tmp;
        }
        return $a;
    }
}