<?php
defined('TYPO3') || die();

return [
    'backend' => [
        'miniorange-backend-2fa' => [
            'target' => \Miniorange\Otp\Middleware\Backend2faMiddleware::class,
            // Run after backend user is authenticated so $GLOBALS['BE_USER'] is set
            'after' => [
                'typo3/cms-backend:backend-user-authentication',
            ],
        ],
    ],
    'frontend' => [
        'miniorange-frontend-authenticator-limit-message' => [
            'target' => \Miniorange\Otp\Middleware\FrontendAuthenticatorLimitMessageMiddleware::class,
            'after' => [
                'typo3/cms-frontend:authentication',
            ],
        ],
    ],
];
