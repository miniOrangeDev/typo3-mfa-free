<?php

// This file registers two frontend plugins for the TYPO3 extension "mfa".
// It makes the plugins "OtpAuthentication" and "TwoFactor" available in the TYPO3 backend plugin wizard,
// each with a custom icon.

defined('TYPO3') || die();

use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use TYPO3\CMS\Extbase\Utility\ExtensionUtility;
