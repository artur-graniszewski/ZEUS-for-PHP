<?php

echo json_encode([
    'pid' => getmypid(),
    'ppid' => posix_getppid(),
]);

echo "\n";

file_put_contents('php://stderr', "ERROR LINE1\nERROR LINE2\n");