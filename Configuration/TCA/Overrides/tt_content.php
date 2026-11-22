<?php
defined('TYPO3') or die();

call_user_func(function () {
    \TYPO3\CMS\Extbase\Utility\ExtensionUtility::registerPlugin(
        'Mfa',
        'OtpAuthentication',
        'OTP Authentication'
    );
});
