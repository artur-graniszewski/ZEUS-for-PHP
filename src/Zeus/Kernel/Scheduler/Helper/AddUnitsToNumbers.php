<?php

namespace Zeus\Kernel\Scheduler\Helper;

use function floor;
use function log;
use function pow;
use function round;

trait AddUnitsToNumbers
{
    public function addUnitsToNumber(int $value, int $precision = 2) : string
    {
        $unit = ["", "K", "M", "G"];
        $exp = floor(log($value, 1000)) | 0;
        $division = pow(1000, $exp);

        if (!$division) {
            return 0;
        }
        return round($value / $division, $precision) . $unit[$exp];
    }
}