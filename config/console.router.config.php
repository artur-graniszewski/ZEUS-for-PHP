<?php

use Zeus\Controller\ZeusController;

return [
    'routes' => [
        'zeus-service' => [
            'options' => [
                'route' => 'zeus (start|list|status|stop) [<service>]',
                'defaults' => [
                    'controller' => ZeusController::class
                ]
            ]
        ]
    ]
];