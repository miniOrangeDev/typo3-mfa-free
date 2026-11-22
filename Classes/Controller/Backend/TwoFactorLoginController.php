<?php

declare(strict_types=1);

namespace Miniorange\Otp\Controller\Backend;

use Miniorange\Otp\Exception\AuthenticatorUserLimitReachedException;
use Miniorange\Otp\Helper\Constants;
use Miniorange\Otp\Helper\MoUtilities;
use Miniorange\Otp\Helper\TypeAssert;
use Miniorange\Otp\Services\AuthenticatorEnrollmentLimiter;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\View\BackendViewFactory;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\HtmlResponse;
use TYPO3\CMS\Core\Http\RedirectResponse;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\PathUtility;

class TwoFactorLoginController
{
    public function __construct(
        private readonly BackendViewFactory $backendViewFactory,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $beUser = TypeAssert::requireBackendUser();
        $queryParams = $request->getQueryParams();
        $methods = $this->normalizeMethods($beUser->getSessionData('mo_2fa_available_methods'));

        if (($queryParams['action'] ?? null) === 'logout') {
            $beUser->setAndSaveSessionData('mo_2fa_pending_tx', null);
            $beUser->logoff();
            $uriBuilder = GeneralUtility::makeInstance(UriBuilder::class);
            $loginUri = $uriBuilder->buildUriFromRoute('login');

            return new RedirectResponse((string)$loginUri);
        }

        if (($queryParams['action'] ?? null) === 'back') {
            $backMethod = TypeAssert::stringValue($queryParams['method'] ?? null);
            if ($backMethod === 'EMAIL') {
                $beUser->setAndSaveSessionData('mo_2fa_temp_email', null);
                $beUser->setAndSaveSessionData('mo_2fa_pending_tx', null);
            } elseif ($backMethod === 'SMS') {
                $beUser->setAndSaveSessionData('mo_2fa_pending_tx', null);
            }
        }

        $parsedBody = TypeAssert::parsedBodyArray($request->getParsedBody());

        $selectedMethod = TypeAssert::stringValue($parsedBody['method'] ?? null);
        if ($selectedMethod === '') {
            $selectedMethod = TypeAssert::stringValue($queryParams['method'] ?? null);
        }
        if ($selectedMethod === '') {
            $selectedMethod = TypeAssert::stringValue($parsedBody['authType'] ?? null);
        }

        if ($selectedMethod === 'email_input' || $selectedMethod === 'EMAIL_INPUT') {
            $selectedMethod = 'EMAIL';
        }
        if ($selectedMethod !== '') {
            $selectedMethod = strtoupper($selectedMethod);
        }

        $emailInput = $this->resolveEmailInput($parsedBody);
        $emailAdded = $this->resolveEmailAdded($parsedBody);

        if ($request->getMethod() === 'POST' && $emailAdded !== '' && trim($emailInput) !== '') {
            $beUser->setAndSaveSessionData('mo_2fa_temp_email', trim($emailInput));
            $selectedMethod = 'EMAIL';
        }

        $uriBuilder = GeneralUtility::makeInstance(UriBuilder::class);

        $connection = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable(Constants::TABLE_TFA_USERS);
        $limiter = GeneralUtility::makeInstance(AuthenticatorEnrollmentLimiter::class);
        if ($limiter->isTwoFactorLimitReached($connection)) {
            return $this->markTwoFactorSatisfiedAndRedirectToBackend($uriBuilder);
        }

        if ($selectedMethod === '') {
            if (count($methods) === 1) {
                $uri = $uriBuilder->buildUriFromRoute('backend', ['method' => $methods[0]]);

                return new RedirectResponse((string)$uri);
            }

            return $this->renderTemplate($request, 'Show', ['methods' => $methods]);
        }

        if ($request->getMethod() === 'POST' && TypeAssert::stringValue($parsedBody['otp'] ?? null) === '') {
            $otpLength = min(max(TypeAssert::intValue($parsedBody['otp_length'] ?? null, 6), 1), 6);
            $mergedOtp = '';
            for ($i = 1; $i <= $otpLength; $i++) {
                $mergedOtp .= TypeAssert::stringValue($parsedBody['otp' . $i] ?? null);
            }
            $parsedBody['otp'] = $mergedOtp;
        }

        $otpValue = TypeAssert::stringValue($parsedBody['otp'] ?? null);
        if ($request->getMethod() === 'POST' && $otpValue !== '') {
            $transactionID = TypeAssert::stringValue($parsedBody['transactionID'] ?? null);
            $secret = TypeAssert::stringValue($parsedBody['secret'] ?? null);
            // Only trust be_users.email or an existing TFA record.
            // Never save email from POST or temporary session data to the TFA table.
            $trustedEmail = TypeAssert::stringFromRow($beUser->user, 'email');
            if ($trustedEmail === '') {
                $trustedEmail = MoUtilities::getUserEmailFromDb(TypeAssert::stringFromRow($beUser->user, 'username'));
            }

            // Verifying this OTP still needs the address it was actually sent to, so the pending
            // transaction is used for validation only - never for persistence.
            $email = $trustedEmail;
            if ($email === '') {
                $pendingTx = $beUser->getSessionData('mo_2fa_pending_tx');
                $pendingTxData = is_array($pendingTx) ? $pendingTx : null;
                if ($pendingTxData !== null && TypeAssert::stringValue($pendingTxData['transactionID'] ?? null) === $transactionID) {
                    $email = TypeAssert::stringValue($pendingTxData['email'] ?? null);
                }
            }
            $phone = TypeAssert::stringValue($parsedBody['phone'] ?? null);

            if ($this->validateOtp($selectedMethod, $otpValue, $email, $transactionID, $secret, $phone, $beUser)) {
                try {
                    MoUtilities::saveUserToTfaTable(
                        TypeAssert::stringFromRow($beUser->user, 'username'),
                        $trustedEmail,
                        $selectedMethod,
                        $secret,
                        $phone
                    );
                } catch (AuthenticatorUserLimitReachedException) {
                    return $this->markTwoFactorSatisfiedAndRedirectToBackend($uriBuilder);
                } catch (\Exception $e) {
                    error_log('Miniorange 2FA DB Save Error: ' . $e->getMessage());

                    return $this->renderOtpScreen($request, $selectedMethod, 'Unable to complete enrollment. Please try again.', [
                        'transactionID' => $transactionID,
                        'email' => $email,
                        'secret' => $secret,
                        'phone' => $phone,
                    ]);
                }

                // The OTP was successfully verified. If no backend email was set,
                // save the verified email to be_users for future logins.
                if ($selectedMethod === 'EMAIL' && $trustedEmail === '' && $email !== '') {
                    MoUtilities::updateBackendUserEmail(TypeAssert::intValue($beUser->user['uid'] ?? null), $email);
                }

                $beUser->setAndSaveSessionData('mo_2fa_verified', true);
                $beUser->uc['mo_2fa_verified'] = true;
                $beUser->writeUC();
                $beUser->setAndSaveSessionData('mo_2fa_available_methods', null);
                $beUser->setAndSaveSessionData('mo_2fa_temp_email', null);
                $beUser->setAndSaveSessionData('mo_2fa_pending_tx', null);

                $dashboardUri = $uriBuilder->buildUriFromRoute('main');

                return new RedirectResponse((string)$dashboardUri);
            }

            return $this->renderOtpScreen($request, $selectedMethod, $this->getOtpErrorMessage($selectedMethod), [
                'transactionID' => $transactionID,
                'email' => $email,
                'secret' => $secret,
                'phone' => $phone,
            ]);
        }

        if ($request->getMethod() === 'POST' && TypeAssert::stringValue($parsedBody['action'] ?? null) === 'save_email') {
            if ($emailInput !== '') {
                $beUser->setAndSaveSessionData('mo_2fa_temp_email', $emailInput);
            }
        }

        $emailFromPost = trim($emailInput);
        // A trusted address on record wins over anything supplied with the request, so that
        // knowing the password is not enough to redirect this login's OTP to another address.
        $emailToUse = trim(TypeAssert::stringFromRow($beUser->user, 'email'));
        if ($emailToUse === '' && ($selectedMethod === 'EMAIL' || $selectedMethod === 'EMAIL_INPUT')) {
            $emailToUse = MoUtilities::getUserEmailFromDb(TypeAssert::stringFromRow($beUser->user, 'username'));
        }
        if ($emailToUse === '') {
            // No trusted address on record: the EmailInput flow legitimately supplies one for
            // delivery only - it is never persisted as the TFA address (see the OTP branch above).
            $emailToUse = TypeAssert::stringValue($beUser->getSessionData('mo_2fa_temp_email'));
        }
        if ($emailToUse === '') {
            $emailToUse = $emailFromPost;
        }

        if (in_array(strtoupper($selectedMethod), ['EMAIL', 'SMS', 'SMS_EMAIL', 'EMAIL_INPUT'], true)) {
            if (
                (strtoupper($selectedMethod) === 'EMAIL' || strtoupper($selectedMethod) === 'EMAIL_INPUT')
                && $emailToUse === ''
            ) {
                return $this->renderTemplate($request, 'EmailInput', ['method' => 'EMAIL']);
            }

            $countryCode = TypeAssert::stringValue($parsedBody['countryCode'] ?? null);
            $rawPhone = TypeAssert::stringValue($parsedBody['phone'] ?? null);

            if ($rawPhone === '') {
                $savedPhone = MoUtilities::getUserPhoneFromDb(TypeAssert::stringFromRow($beUser->user, 'username'));
                if ($savedPhone !== '') {
                    $rawPhone = $savedPhone;
                    $countryCode = '';
                }
            }

            $phoneArg = $countryCode !== '' ? $countryCode . $rawPhone : $rawPhone;
            $shouldShowSmsForm = ($selectedMethod === 'SMS' || $selectedMethod === 'SMS_EMAIL') && strlen($phoneArg) < 5;

            if ($shouldShowSmsForm) {
                return $this->renderOtpScreen($request, $selectedMethod, '', ['email' => $emailToUse]);
            }

            $pendingTx = $beUser->getSessionData('mo_2fa_pending_tx');
            $pendingTxData = is_array($pendingTx) ? $pendingTx : null;
            $isReloadOrReshow = ($request->getMethod() === 'GET' && $pendingTxData !== null
                    && TypeAssert::stringValue($pendingTxData['method'] ?? null) === $selectedMethod)
                || ($request->getMethod() === 'POST'
                    && TypeAssert::stringValue($parsedBody['transactionID'] ?? null) !== ''
                    && trim(TypeAssert::stringValue($parsedBody['otp'] ?? null)) === '');
            if ($isReloadOrReshow) {
                if ($pendingTxData !== null && TypeAssert::stringValue($pendingTxData['method'] ?? null) === $selectedMethod) {
                    return $this->renderOtpScreen(
                        $request,
                        TypeAssert::stringValue($pendingTxData['method'] ?? $selectedMethod),
                        '',
                        [
                            'transactionID' => TypeAssert::stringValue($pendingTxData['transactionID'] ?? null),
                            'email' => TypeAssert::stringValue($pendingTxData['email'] ?? null) ?: $emailToUse,
                            'phone' => TypeAssert::stringValue($pendingTxData['phone'] ?? null) ?: $phoneArg,
                        ]
                    );
                }
                if ($pendingTxData !== null && $request->getMethod() === 'POST' && TypeAssert::stringValue($parsedBody['transactionID'] ?? null) !== '') {
                    return $this->renderOtpScreen($request, $selectedMethod, '', [
                        'transactionID' => TypeAssert::stringValue($parsedBody['transactionID'] ?? null),
                        'email' => TypeAssert::stringValue($parsedBody['email'] ?? null) ?: $emailToUse,
                        'phone' => TypeAssert::stringValue($parsedBody['phone'] ?? null) ?: $phoneArg,
                    ]);
                }
            }

            $otpMethod = $selectedMethod === 'EMAIL_INPUT' ? 'EMAIL' : $selectedMethod;
            $response = TypeAssert::decodeJsonArray(
                MoUtilities::sendOtpViaMiniorange($emailToUse, strtolower($otpMethod), $phoneArg)
            );

            $transactionID = '';
            $error = TypeAssert::stringValue($response['message'] ?? null);

            if (($response['status'] ?? null) === 'SUCCESS') {
                $transactionID = TypeAssert::stringValue($response['txId'] ?? null);
                $error = '';
                $beUser->setAndSaveSessionData('mo_2fa_pending_tx', [
                    'method' => $otpMethod,
                    'transactionID' => $transactionID,
                    'email' => $emailToUse,
                    'phone' => $phoneArg,
                ]);
            } else {
                if (MoUtilities::isTransactionLimitExceededMessage($error)) {
                    $beUser->setAndSaveSessionData('mo_2fa_verified', true);
                    $beUser->uc['mo_2fa_verified'] = true;
                    $beUser->writeUC();
                    $beUser->setAndSaveSessionData('mo_2fa_available_methods', null);
                    $beUser->setAndSaveSessionData('mo_2fa_temp_email', null);
                    $beUser->setAndSaveSessionData('mo_2fa_pending_tx', null);
                    if (MoUtilities::isSmsTwoFactorMethod($selectedMethod)) {
                        MoUtilities::addBackendFlashMessage(
                            'The 2FA transaction limit has been exceeded. Please contact your administrator.',
                            '',
                            ContextualFeedbackSeverity::ERROR
                        );
                    } else {
                        MoUtilities::addBackendFlashMessage(
                            'The transaction limit has been exceeded.  Please upgrade your plan or contact your administrator.',
                            'Transaction Limit Exceeded',
                            ContextualFeedbackSeverity::WARNING
                        );
                    }
                    $dashboardUri = $uriBuilder->buildUriFromRoute('main');

                    return new RedirectResponse((string)$dashboardUri);
                }
                $error = $error !== '' ? $error : 'Error sending OTP';
                if ($selectedMethod === 'SMS' || $selectedMethod === 'SMS_EMAIL') {
                    return $this->renderOtpScreen($request, $selectedMethod, $error, [
                        'email' => $emailToUse,
                        'phone' => $rawPhone,
                    ]);
                }
            }

            return $this->renderOtpScreen($request, $otpMethod, $error, [
                'transactionID' => $transactionID,
                'email' => $emailToUse,
                'phone' => $phoneArg,
            ]);
        }

        return $this->renderOtpScreen($request, $selectedMethod);
    }

    /**
     * @param array<string, mixed> $parsedBody
     */
    private function resolveEmailInput(array $parsedBody): string
    {
        $emailInput = TypeAssert::stringValue($parsedBody['email'] ?? null);
        if ($emailInput !== '') {
            return $emailInput;
        }

        foreach ($parsedBody as $value) {
            if (is_array($value) && isset($value['email'])) {
                return TypeAssert::stringValue($value['email']);
            }
        }

        return '';
    }

    /**
     * @param array<string, mixed> $parsedBody
     */
    private function resolveEmailAdded(array $parsedBody): string
    {
        $emailAdded = TypeAssert::stringValue($parsedBody['email_added'] ?? null);
        if ($emailAdded !== '') {
            return $emailAdded;
        }

        foreach ($parsedBody as $value) {
            if (is_array($value) && isset($value['email_added'])) {
                return TypeAssert::stringValue($value['email_added']);
            }
        }

        return '';
    }

    /**
     * @return list<string>
     */
    private function normalizeMethods(mixed $methods): array
    {
        if (!is_array($methods)) {
            return [];
        }

        return TypeAssert::stringListFromArray($methods);
    }

    private function getOtpErrorMessage(string $method): string
    {
        return match (strtoupper($method)) {
            'EMAIL', 'EMAIL_INPUT' => 'Invalid OTP. Please try again.',
            'SMS', 'SMS_EMAIL' => 'Invalid OTP. Please try again.',
            'GOOGLE', 'MICROSOFT' => 'Invalid code. Please try again.',
            default => 'Invalid verification code. Please try again.',
        };
    }

    /**
     * @param array<string, mixed> $extraVars
     */
    private function renderOtpScreen(ServerRequestInterface $request, string $method, string $error = '', array $extraVars = []): ResponseInterface
    {
        $method = strtoupper($method);
        if ($method === 'EMAIL_INPUT') {
            $method = 'EMAIL';
        }

        $templateName = 'Authenticator';

        $variables = [
            'method' => $method,
            'error' => $error,
            'authType' => $method,
            'otp_length' => MoUtilities::getOtpLength(),
            'issuer' => 'TYPO3 Backend',
        ];

        if ($method === 'EMAIL') {
            $templateName = 'EmailVerify';
            $email = TypeAssert::stringValue($extraVars['email'] ?? null);
            if ($email !== '') {
                $atPosition = strpos($email, '@');
                $masked = $atPosition === false
                    ? substr($email, 0, 1) . '******'
                    : substr($email, 0, 1) . '******' . substr($email, $atPosition);
                $variables['email_display'] = $masked;
                $variables['maskedEmail'] = $masked;
            }
        } elseif ($method === 'SMS') {
            if (TypeAssert::stringValue($extraVars['transactionID'] ?? null) !== '') {
                $templateName = 'SmsVerify';
                $phone = TypeAssert::stringValue($extraVars['phone'] ?? null);
                if (strlen($phone) >= 4) {
                    $variables['maskedPhone'] = '******' . substr($phone, -4);
                } else {
                    $variables['maskedPhone'] = $phone !== '' ? '****' : '';
                }
            } else {
                $templateName = 'SmsPhone';
            }
        } elseif ($method === 'SMS_EMAIL') {
            if (TypeAssert::stringValue($extraVars['transactionID'] ?? null) !== '') {
                $templateName = 'EmailSmsVerify';
            } else {
                $templateName = 'EmailSmsSetup';
            }
        } elseif ($method === 'GOOGLE' || $method === 'MICROSOFT') {
            $beUser = TypeAssert::requireBackendUser();
            $username = TypeAssert::stringFromRow($beUser->user, 'username');
            $userEmail = trim(TypeAssert::stringFromRow($beUser->user, 'email'));
            $accountLabel = $userEmail !== '' ? $userEmail : $username;
            if ($accountLabel === '') {
                $accountLabel = 'user';
            }
            $variables['email'] = $accountLabel;
            $variables['accountLabel'] = $accountLabel;

            $connection = GeneralUtility::makeInstance(ConnectionPool::class)
                ->getConnectionForTable(Constants::TABLE_TFA_USERS);
            $limiter = GeneralUtility::makeInstance(AuthenticatorEnrollmentLimiter::class);
            if (!empty($extraVars['enrollmentBlocked']) || $limiter->isTwoFactorLimitReached($connection)) {
                return $this->markTwoFactorSatisfiedAndRedirectToBackend(
                    GeneralUtility::makeInstance(UriBuilder::class)
                );
            }

            $secret = MoUtilities::getAuthenticatorSecretForBackendUser($username);

            if ($secret !== null) {
                $variables['secret'] = $secret;
                $variables['isConfigured'] = 1;
            } else {
                $variables['secret'] = TypeAssert::stringValue($extraVars['secret'] ?? null) ?: MoUtilities::generateTotpSecret(16);
                $variables['isConfigured'] = 0;
            }
            $variables['qrCodeUrl'] = MoUtilities::generateQrCodeDataUri(
                $accountLabel,
                TypeAssert::stringValue($variables['secret']),
                $method
            );
        }

        $variables = array_merge($variables, $extraVars);

        return $this->renderTemplate($request, $templateName, $variables);
    }

    /**
     * @param array<string, mixed> $variables
     */
    private function renderTemplate(ServerRequestInterface $request, string $templateName, array $variables = []): ResponseInterface
    {
        $currentUri = $request->getUri();
        $currentQuery = $currentUri->getQuery();
        $logoutQuery = $currentQuery . ($currentQuery ? '&' : '') . 'action=logout';
        $logoutUrl = (string)$currentUri->withQuery($logoutQuery);
        $backToShowTemplates = ['EmailInput', 'SmsPhone', 'EmailSmsSetup', 'Authenticator'];
        $beUser = TypeAssert::requireBackendUser();
        $methods = $this->normalizeMethods($beUser->getSessionData('mo_2fa_available_methods'));
        $hasMultipleMethods = count($methods) > 1;

        $verifyBackMap = [
            'EmailVerify' => ['method' => 'EMAIL'],
            'SmsVerify' => ['method' => 'SMS'],
        ];

        $username = TypeAssert::stringFromRow($beUser->user, 'username');
        $userEmail = trim(TypeAssert::stringFromRow($beUser->user, 'email'));

        $hasPreconfiguredEmail = $userEmail !== '' || MoUtilities::getUserEmailFromDb($username) !== '';
        $isEmailConfigured = $templateName === 'EmailVerify' && $hasPreconfiguredEmail;
        $isSmsConfigured = $templateName === 'SmsVerify'
            && MoUtilities::getUserPhoneFromDb($username) !== '';

        if ($isEmailConfigured) {
            if ($hasMultipleMethods) {
                $backUriBuilder = GeneralUtility::makeInstance(UriBuilder::class);
                $backUrl = (string)$backUriBuilder->buildUriFromRoute('backend');
            } else {
                $backUrl = $logoutUrl;
            }
        } elseif ($isSmsConfigured && !$hasMultipleMethods) {
            $backUrl = $logoutUrl;
        } elseif (isset($verifyBackMap[$templateName])) {
            $backUriBuilder = GeneralUtility::makeInstance(UriBuilder::class);
            $backUrl = (string)$backUriBuilder->buildUriFromRoute('backend', [
                'method' => $verifyBackMap[$templateName]['method'],
                'action' => 'back',
            ]);
        } elseif (in_array($templateName, $backToShowTemplates, true) && $hasMultipleMethods) {
            $backUriBuilder = GeneralUtility::makeInstance(UriBuilder::class);
            $backUrl = (string)$backUriBuilder->buildUriFromRoute('backend');
        } else {
            $backUrl = $logoutUrl;
        }

        $formAction = (string)$request->getUri();

        if (isset($variables['method'])) {
            $queryParams = $request->getQueryParams();
            $currentMethod = $queryParams['method'] ?? null;
            $methodValue = TypeAssert::stringValue($variables['method']);
            $methodAlreadyInUrl = $currentMethod === $methodValue
                || (is_array($currentMethod) && in_array($methodValue, $currentMethod, true));
            if (!$methodAlreadyInUrl) {
                $separator = (strpos($formAction, '?') === false) ? '?' : '&';
                $formAction .= $separator . 'method=' . urlencode($methodValue);
            }
        }

        $scriptPath = PathUtility::getPublicResourceWebPath('EXT:mfa/Resources/Public/JavaScript/Backend2FA/Authenticator.js');
        $scriptUrl = '';
        if ($scriptPath !== '') {
            $siteUrl = rtrim(TypeAssert::stringValue(GeneralUtility::getIndpEnv('TYPO3_SITE_URL')), '/');
            $scriptUrl = $siteUrl . '/' . ltrim($scriptPath, '/');
        }

        $variables['backUrl'] = $backUrl;
        $variables['formAction'] = $formAction;
        $variables['scriptUrl'] = $scriptUrl;

        return new HtmlResponse(TypeAssert::htmlString(
            $this->renderFluidTemplate($request, $templateName, $variables)
        ));
    }

    /**
     * The Composer package name is passed as second argument because this route is registered in
     * Configuration/Backend/Routes.php and therefore carries no 'packageName' route option that
     * BackendViewFactory could derive the EXT:mfa template path from on its own.
     *
     * @param array<string, mixed> $variables
     */
    private function renderFluidTemplate(ServerRequestInterface $request, string $templateName, array $variables): string
    {
        $view = $this->backendViewFactory->create($request, ['miniorange/mfa']);
        $view->assignMultiple($variables);

        return $view->render('Backend2FA/' . $templateName);
    }

    private function validateOtp(
        string $method,
        string $otp,
        string $email,
        string $txId,
        string $secret,
        string $phone,
        BackendUserAuthentication $beUser
    ): bool {
        $method = strtoupper($method);

        if ($method === 'GOOGLE' || $method === 'MICROSOFT') {
            $response = TypeAssert::decodeJsonArray(MoUtilities::verifyGauthCode($otp, $email, $secret));

            return ($response['status'] ?? null) === 'SUCCESS';
        }

        if ($method === 'EMAIL' || $method === 'SMS' || $method === 'SMS_EMAIL') {
            $username = TypeAssert::stringFromRow($beUser->user, 'username');
            $response = TypeAssert::decodeJsonArray(
                MoUtilities::verifyOtpViaMiniorange($username, $otp, strtolower($method), $txId, $phone)
            );

            return ($response['status'] ?? null) === 'SUCCESS';
        }

        return false;
    }

    private function markTwoFactorSatisfiedAndRedirectToBackend(UriBuilder $uriBuilder): RedirectResponse
    {
        $beUser = TypeAssert::requireBackendUser();
        $beUser->setAndSaveSessionData('mo_2fa_verified', true);
        $beUser->uc['mo_2fa_verified'] = true;
        $beUser->writeUC();
        $beUser->setAndSaveSessionData('mo_2fa_available_methods', null);
        $beUser->setAndSaveSessionData('mo_2fa_temp_email', null);
        $beUser->setAndSaveSessionData('mo_2fa_pending_tx', null);
        MoUtilities::addBackendFlashMessage(
            AuthenticatorUserLimitReachedException::message(),
            '',
            ContextualFeedbackSeverity::ERROR
        );

        return new RedirectResponse((string)$uriBuilder->buildUriFromRoute('main'));
    }
}
