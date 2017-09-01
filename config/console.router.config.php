<?php

use Zeus\Controller\MainController;
use Zeus\Controller\WorkerController;

return [
    'routes' => [
        'zeus-service' => [
            'options' => [
                'route' => 'zeus (start|list|status|stop) [<service>] [<jsonstartparams>]',
                'defaults' => [
                    'controller' => MainController::class
                ]
            ]
        ],

        'zeus-process' => [
            'options' => [
                'route' => 'zeus (scheduler|worker) [<service>] [<jsonstartparams>]',
                'defaults' => [
                    'controller' => WorkerController::class
                ]
            ]
        ]
    ]
];