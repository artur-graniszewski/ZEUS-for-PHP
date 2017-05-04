<?php

use Zeus\Controller\ConsoleController;
use Zeus\Controller\ProcessController;

return [
    'routes' => [
        'zeus-service' => [
            'options' => [
                'route' => 'zeus (start|list|status|stop) [<service>]',
                'defaults' => [
                    'controller' => ConsoleController::class
                ]
            ]
        ],
    ]
];