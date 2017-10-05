<?php

namespace Zeus\Kernel\Scheduler\Helper;

trait AddUnitsToNumbers
{
    public function addUnitsToNumber($value, $precision = 2)
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