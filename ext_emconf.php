<?php

$_EXTKEY = isset($_EXTKEY) && is_string($_EXTKEY) ? $_EXTKEY : 'mfa';

/** @var array<string, array<string, mixed>> $EM_CONF */
$EM_CONF = isset($EM_CONF) && is_array($EM_CONF) ? $EM_CONF : [];

$EM_CONF[$_EXTKEY] = [
    'title' => 'miniOrange 2FA Extension',
    'description' => 'OTP (One-Time Password) authentication for TYPO3 login',
    'constraints' => [
        'depends' => [
            'typo3' => '10.0.0-13.4.0',
        ],
    ],
    'author' => 'miniOrange',
    'author_email' => 'info@miniorange.com',
    'category' => 'plugin',
    'version' => '1.0.0',
    'state' => 'stable',
    'autoload' => [
        'psr-4' => [
            'Miniorange\\Otp\\' => 'Classes/',
        ],
    ]
];
