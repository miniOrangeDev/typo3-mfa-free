<?php

use Miniorange\Otp\Controller\Backend2FAController;

/**
 * Definitions for modules provided by EXT:examples
 */
return [
    'tools_mfa' => [
        'parent' => 'tools',
        'position' => ['after' => 'tools_extensionmanager'],
        'access' => 'user,group',
        'workspaces' => 'live',
        'iconIdentifier' => 'mfa-plugin-bekey',
        'path' => '/module/tools/backend2fakey',
        'labels' => 'LLL:EXT:mfa/Resources/Private/Language/locallang_bekey.xlf',
        'extensionName' => 'Mfa',
        'controllerActions' => [
            Backend2FAController::class => [
                'request',
                'saveCustomer2faRule',
                'users',
                'bulkDisable2fa',
                'bulkReset2fa',
                'reset2faUser',
                'submitSupportAjax',
                'submit'
            ],
        ],
    ]
];