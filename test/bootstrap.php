<?php

chdir(dirname(__DIR__));
if (file_exists('../../vendor/autoload.php')) {
    $loader = require '../../vendor/autoload.php';
} else {
    $loader = require '../../../vendor/autoload.php';
}
$loader->add('ZeusTest', __DIR__);
