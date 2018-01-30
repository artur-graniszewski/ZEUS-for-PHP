<?php

echo json_encode([
    'pid' => getmypid(),
    'ppid' => posix_getppid(),
]);