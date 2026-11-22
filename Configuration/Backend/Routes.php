<?php

return [
    'backend' => [
        'path' => '/backend/two-factor-login',
        'target' => \Miniorange\Otp\Controller\Backend\TwoFactorLoginController::class . '::handle',
        'access' => 'admin',
        'before' => ['login'],
    ],
];
