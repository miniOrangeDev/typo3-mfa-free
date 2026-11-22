<?php
defined('TYPO3') || die();

use TYPO3\CMS\Core\Imaging\IconProvider\BitmapIconProvider;
use TYPO3\CMS\Core\Imaging\IconRegistry;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Utility\ExtensionUtility;
use Miniorange\Otp\Controller\OtpAuthenticationController;
use Miniorange\Otp\Services\Backend2FAUserAuthenticationService;
use Miniorange\Otp\Services\Frontend2FAUserAuthenticationService;

ExtensionUtility::configurePlugin(
    'Mfa',
    'OtpAuthentication',
    [
        OtpAuthenticationController::class => 'show,submit,sendOtpSms,verifyOtpSms,verifyOtpEmail,setupEmailSms,verifyEmailSms,verifyAuthenticator,verifyExistingUser,processEmailInput,emailVerify',
    ],
    [
        OtpAuthenticationController::class => 'show,submit,sendOtpSms,verifyOtpSms,verifyOtpEmail,setupEmailSms,verifyEmailSms,verifyAuthenticator,verifyExistingUser,processEmailInput,emailVerify',
    ]
);

ExtensionManagementUtility::addService(
    'mfa',
    'auth',
    Frontend2FAUserAuthenticationService::class,
    [
        'title' => 'Frontend 2FA Email Authentication',
        'description' => 'Provides frontend user authentication with email address and enforces two-factor authentication (2FA) for enhanced security.',
        'subtype' => 'getUserFE,authUserFE',
        'available' => true,
        'priority' => 90,
        'quality' => 50,
        'os' => '',
        'exec' => '',
        'className' => Frontend2FAUserAuthenticationService::class,
    ]
);

ExtensionManagementUtility::addService(
    'mfa',
    'auth',
    Backend2FAUserAuthenticationService::class,
    [
        'title' => 'Backend 2FA Authentication',
        'description' => 'Authenticates backend users and invokes 2FA',
        'subtype' => 'getUserBE,authUserBE',
        'available' => true,
        'priority' => 90,
        'quality' => 50,
        'os' => '',
        'exec' => '',
        'className' => Backend2FAUserAuthenticationService::class,
    ]
);

ExtensionManagementUtility::addPageTSConfig(
    '@import "EXT:mfa/Configuration/TsConfig/Page/All.tsconfig"'
);

$iconRegistry = GeneralUtility::makeInstance(IconRegistry::class);
$iconRegistry->registerIcon(
    'mfa-plugin-bekey',
    BitmapIconProvider::class,
    ['source' => 'EXT:mfa/Resources/Public/Icons/miniorange.png']
);
$iconRegistry->registerIcon(
    'mfa-plugin-otpauthentication',
    BitmapIconProvider::class,
    ['source' => 'EXT:mfa/Resources/Public/Icons/miniorange.png']
);
