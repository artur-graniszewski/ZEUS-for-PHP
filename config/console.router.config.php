<?php

use Zeus\Controller\ConsoleController;

return [
    'routes' => [
        'zeus-service' => [
            'options' => [
                'route' => 'zeus (start|list|status|stop) [<service>]',
                'defaults' => [
                    'controller' => ConsoleController::class
                ]
            ]
        ]
    ]
];