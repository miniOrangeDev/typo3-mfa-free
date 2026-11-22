<?php
declare(strict_types=1);

namespace Miniorange\Otp\Controller;

use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Frontend\Authentication\FrontendUserAuthentication;
use TYPO3\CMS\Core\Context\Context;
use Miniorange\Otp\Helper\MoUtilities;
use Miniorange\Otp\Helper\Constants;
use Miniorange\Otp\Exception\AuthenticatorUserLimitReachedException;
use Miniorange\Otp\Services\AuthenticatorEnrollmentLimiter;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\HtmlResponse;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;
use Psr\Http\Message\ServerRequestInterface;
use Miniorange\Otp\Helper\TypeAssert;
use TYPO3\CMS\Fluid\View\FluidViewAdapter;
use TYPO3Fluid\Fluid\View\AbstractTemplateView;


class OtpAuthenticationController extends ActionController
{
    protected int|string|null $storagePid = null;
    
    /**
     * function to show the method selection page
     *
     */
    public function showAction(): ResponseInterface
    {


        session_start();

        $queryParams = $this->request->getQueryParams();
        // Extbase namespaces plugin arguments (e.g. tx_mfa_otpauthentication[method])
        // for links built via f:link.action/UriBuilder, so they must be read through
        // the request's own argument resolution first - a flat $queryParams['method']
        // lookup only ever matches the plain query string the login redirect builds.
        $method = $this->stringArgument('method');
        if ($method === '') {
            $method = TypeAssert::stringValue($queryParams['method'] ?? $_SESSION['otp_method'] ?? null);
        }
        $enabledMethods = $_SESSION['otp_enabled_methods'] ?? null;

        $email = TypeAssert::stringValue($queryParams['email'] ?? $_SESSION['otp_email'] ?? null);
        $user_pid = TypeAssert::stringValue($queryParams['user_pid'] ?? $_SESSION['otp_user_pid'] ?? null);
        
        // If enabled_methods is a string, convert it to array
        if (is_string($enabledMethods)) {
            $enabledMethods = explode(',', $enabledMethods);
        }
        if (TypeAssert::stringValue($_SESSION['otp_username'] ?? null) !== ''
            && $this->isFrontendTwoFactorSetupBlocked()
        ) {
            return $this->loginWithoutTwoFactorChallenge();
        }
        
        // Assign common variables
        $this->view->assign('email', $email);
        $this->view->assign('user_pid', $user_pid);
        // Determine which template to show based on method


        if ($method === 'select') {
            // Show method selection page
            $this->view->assign('enabledMethods', $enabledMethods);
            $this->view->assign('showMethodSelection', true);
            $this->setViewTemplate('Show'); // This will show the method selection page
        } elseif ($method === 'email' || $method === 'EMAIL') {
            $prepareResponse = $this->sendEmailOtpIfNeeded(
                $email,
                TypeAssert::intValue($_SESSION['otp_email_added'] ?? 0)
            );
            if ($prepareResponse !== null) {
                return $prepareResponse;
            }

            return $this->redirect('emailVerify');
        } elseif ($method === 'email_input') {
            // Re-show the email collection page (Back target for the single-email OTP flow)
            $this->view->assign('otp_length', MoUtilities::getOtpLength());
            $this->assignBackNavigation(null);
            $this->setViewTemplate('EmailInput');
        } elseif ($method === 'sms' || $method === 'SMS') {
            // Show SMS verification page
            $otp_length = MoUtilities::getOtpLength();
            $this->view->assign('otp_length', $otp_length);
            $this->view->assign('showSmsVerification', true);
            $this->assignBackNavigation(null);
            $this->setViewTemplate('SmsPhone');
        }elseif($method === 'sms_email' || $method === 'SMS_EMAIL'){
            $otp_length = MoUtilities::getOtpLength();
            $this->view->assign('otp_length', $otp_length);
            $this->view->assign('showEmailSmsSetup', true);
            $this->setViewTemplate('EmailSmsSetup');
        } elseif ($method === 'existing_user') {
            // Show existing user verification page
            $existingUser = $this->existingUserFromSession();

            if ($existingUser !== null) {
                $otp_length = MoUtilities::getOtpLength();
                $this->view->assign('otp_length', $otp_length);

                $active_method = TypeAssert::stringFromRow($existingUser, 'active_method');
                $response = [];
                if ($active_method === 'sms' || $active_method === 'sms_and_email' || $active_method === 'email') {
                    $existingEmail = TypeAssert::stringFromRow($existingUser, 'email');
                    if ($this->pendingOtpMatches('existing_user', $existingEmail)) {
                        $response = [
                            'status' => 'SUCCESS',
                            'txId' => $this->sessionString('otp_transactionID'),
                        ];
                    } else {
                        $response = $this->decodeApiResponse(
                            MoUtilities::sendOtpViaMiniorange(
                                $existingEmail,
                                $active_method,
                                TypeAssert::stringFromRow($existingUser, 'phone')
                            )
                        );
                        if (($response['status'] ?? null) === 'SUCCESS') {
                            $this->storePendingOtp(
                                'existing_user',
                                TypeAssert::stringValue($response['txId'] ?? null),
                                $existingEmail
                            );
                        }
                    }

                    
                if(isset($response['status']) && $response['status'] == 'SUCCESS'){
                    $this->view->assign('transactionID', $response['txId']);
                    $this->view->assign('phone', TypeAssert::stringFromRow($existingUser, 'phone'));
                    $this->setViewTemplate('SmsVerify');
                    $this->view->assign('username', TypeAssert::stringFromRow($existingUser, 'username'));
                    $this->view->assign('email', TypeAssert::stringFromRow($existingUser, 'email'));
                    $this->view->assign('active_method', TypeAssert::stringFromRow($existingUser, 'active_method'));
                    $this->view->assign('user_pid', TypeAssert::stringFromRow($existingUser, 'user_pid'));
                    $this->view->assign('phone', TypeAssert::stringFromRow($existingUser, 'phone'));
                    $this->setViewTemplate('ExistingUserVerify');
                }else{
                    $errorMessage = TypeAssert::stringValue($response['message'] ?? null, 'Error sending OTP');
                    if (
                        MoUtilities::isSmsTwoFactorMethod($active_method)
                        && MoUtilities::isTransactionLimitExceededMessage($errorMessage)
                    ) {
                        $limitResponse = $this->continueLoginWithoutSmsTwoFactor();
                        if ($limitResponse !== null) {
                            return $limitResponse;
                        }
                    }
                    $this->addFlashMessage($errorMessage, 'Error');
                    return $this->redirectToUri('/');

                }
                
                }else{
                    // active method is authenticator
                    $otp_length= 6;
                    $this->view->assign('otp_length', $otp_length);
                    $this->view->assign('transactionID', TypeAssert::stringFromRow($existingUser, 'secret'));
                    $this->view->assign('secret', TypeAssert::stringFromRow($existingUser, 'secret'));
                    $this->view->assign('phone', TypeAssert::stringFromRow($existingUser, 'phone'));
                    $this->setViewTemplate('SmsVerify');
                    $this->view->assign('username', TypeAssert::stringFromRow($existingUser, 'username'));
                    $this->view->assign('email', TypeAssert::stringFromRow($existingUser, 'email'));
                    $this->view->assign('active_method', TypeAssert::stringFromRow($existingUser, 'active_method'));
                    $this->view->assign('user_pid', TypeAssert::stringFromRow($existingUser, 'user_pid'));
                    $this->view->assign('phone', TypeAssert::stringFromRow($existingUser, 'phone'));
                    $this->setViewTemplate('ExistingUserVerify');

                }

            } else {
                // Fallback to method selection if session data is missing
                $this->view->assign('enabledMethods', $enabledMethods);
                $this->view->assign('showMethodSelection', true);
                $this->setViewTemplate('Show');
            }
        } elseif ($method === 'google' || $method === 'GOOGLE') {
            $blockedResponse = $this->redirectIfTwoFactorSetupBlocked();
            if ($blockedResponse !== null) {
                return $blockedResponse;
            }
            $this->assignAuthenticatorEnrollmentView('Google Authenticator', $email !== '' ? $email : null);
        } elseif ($method === 'microsoft' || $method === 'MICROSOFT') {
            $blockedResponse = $this->redirectIfTwoFactorSetupBlocked();
            if ($blockedResponse !== null) {
                return $blockedResponse;
            }
            $this->assignAuthenticatorEnrollmentView('Microsoft Authenticator', $email !== '' ? $email : null);
        }
        else {
            // Default: show method selection page
            $this->view->assign('enabledMethods', $enabledMethods);
            $this->view->assign('showMethodSelection', true);
            $this->setViewTemplate('Show');
        }
        
        return $this->htmlResponse();
    }

    /**
     * function to submit the method selection page
     *
     */
    public function submitAction(): ResponseInterface
    {
        
        $selectedMethod = $this->stringArgument('method');
        if ($selectedMethod === 'sms') {
            $this->assignBackNavigation(null);
            $this->setViewTemplate('SmsPhone');

            return new HtmlResponse(TypeAssert::htmlString($this->view->render()));
        }

        session_start();
        $queryParams = $this->request->getQueryParams();
        $email = $this->stringArgument('email');
        if ($email === '') {
            $email = TypeAssert::stringValue($queryParams['email'] ?? $_SESSION['otp_email'] ?? null);
        }
        $user_pid = TypeAssert::stringValue($queryParams['user_pid'] ?? $_SESSION['otp_user_pid'] ?? null);

        switch ($selectedMethod) {
            case 'email':
                $email = TypeAssert::stringValue($queryParams['email'] ?? $_SESSION['otp_email'] ?? null);
                $prepareResponse = $this->sendEmailOtpIfNeeded($email, 0);
                if ($prepareResponse !== null) {
                    return $prepareResponse;
                }

                return $this->redirect('emailVerify');
            case 'sms':
                // Show SMS verification page
                $otp_length = MoUtilities::getOtpLength();
                $this->view->assign('otp_length', $otp_length);
                $this->view->assign('showSmsVerification', true);
                $this->setViewTemplate('SmsPhone');
                return new HtmlResponse(TypeAssert::htmlString($this->view->render()));
            case 'google':
                $blockedResponse = $this->redirectIfTwoFactorSetupBlocked();
                if ($blockedResponse !== null) {
                    return $blockedResponse;
                }
                $this->assignAuthenticatorEnrollmentView('Google Authenticator', $email !== '' ? $email : null);

                return new HtmlResponse(TypeAssert::htmlString($this->view->render()));
            case 'microsoft':
                $blockedResponse = $this->redirectIfTwoFactorSetupBlocked();
                if ($blockedResponse !== null) {
                    return $blockedResponse;
                }
                $this->assignAuthenticatorEnrollmentView('Microsoft Authenticator', $email !== '' ? $email : null);

                return new HtmlResponse(TypeAssert::htmlString($this->view->render()));
            case 'sms_email':
                // Show Email+SMS setup page
                $this->view->assign('email', $email);
                $this->setViewTemplate('EmailSmsSetup');
                return new HtmlResponse(TypeAssert::htmlString($this->view->render()));
            default:
                // Handle invalid selection
                break;
        }
        
        // Check if we have a user waiting for OTP verification
        $feUserAuth = GeneralUtility::makeInstance(FrontendUserAuthentication::class);
        if (empty($feUserAuth->getSessionData('two_factor_user'))) {
            $this->addFlashMessage('Session expired. Please login again.', 'Error');
            return $this->redirect('show');
        }

        $this->addFlashMessage('Invalid OTP code', 'Error');
        return $this->redirect('show');
    }

    /**
     * function to generate the authenticator secret
     *
     */
    protected function generateAuthenticatorSecret(): string
    {
        // Generate a 32-character base32 secret (standard for TOTP)
        $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $secret = '';
        for ($i = 0; $i < 32; $i++) {
            $secret .= $chars[random_int(0, strlen($chars) - 1)];
        }
        return $secret;
    }

    /**
     * function to generate the qr code url
     *
     */
    protected function generateQrCodeUrl(string $email, string $secret, string $authType = 'Google Authenticator'): string
    {
        $issuer = 'miniOrange';
        $account = $email;
        // URL encode the issuer and account to handle special characters
        $encodedIssuer = urlencode($issuer);
        $encodedAccount = urlencode($account);
        $otpauth = "otpauth://totp/{$encodedIssuer}:{$encodedAccount}?secret={$secret}&issuer={$encodedIssuer}&algorithm=SHA1&digits=6&period=30";
        return $otpauth;
    }

    /**
     * function to generate the qr code data uri
     *
     */
    protected function generateQrCodeDataUri(string $email, string $secret, string $authType = 'Google Authenticator'): string
    {
        return MoUtilities::generateQrCodeDataUri($email, $secret, $authType);
    }

    /**
     * function to verify the authenticator code
     *
     */
    public function verifyAuthenticatorAction(): ResponseInterface
    {
        $enteredCode = $this->stringArgument('otp');
        $authType = $this->stringArgument('authType');
        session_start();
        $secret = $this->request->hasArgument('secret')
            ? $this->stringArgument('secret')
            : TypeAssert::stringValue($_SESSION['otp_secret'] ?? null);

        $username = TypeAssert::stringValue($_SESSION['otp_username'] ?? null);
        $email = TypeAssert::stringValue($_SESSION['otp_email'] ?? null);
        $this->storagePid = TypeAssert::stringValue($_SESSION['otp_user_pid'] ?? null);

        $response = $this->decodeApiResponse(MoUtilities::verifyGauthCode($enteredCode, $email, $secret));

        if (($response['status'] ?? null) === 'SUCCESS') {

            // OTP is correct, login the user in typo3 frontend 
            $user = MoUtilities::fetchUserFromUsername($username, $this->storagePid);

            $request = $this->currentServerRequest();
            if (!is_array($user)) {
                $user = [];
            }
            $user['active_method'] = $authType;
            $user['secret'] = $secret;

            $phone = '';
            try {
                $this->saveUserInMiniorangeTfUsers($user, $phone,$response);
            } catch (AuthenticatorUserLimitReachedException) {
                $limitResponse = $this->loginWithoutTwoFactorAfterLimit();
                if ($limitResponse !== null) {
                    return $limitResponse;
                }

                $this->view->assign('error', AuthenticatorUserLimitReachedException::message());
                $this->view->assign('secret', $secret);
                $this->view->assign('email', $email);
                $this->view->assign('authType', $authType);
                $this->setViewTemplate('Authenticator');

                return new HtmlResponse(TypeAssert::htmlString($this->view->render()));
            }

            $frontendUser = GeneralUtility::makeInstance(\TYPO3\CMS\Frontend\Authentication\FrontendUserAuthentication::class);
            $frontendUser->start($request);

            $session = $frontendUser->createUserSession($user);
            $frontendUser->storeSessionData();
            setcookie("fe_typo_user", $session->getJwt(), 0, '/');
            return $this->redirectToUri('/');
        } else {
            // Code is incorrect, show error on the same page
            $this->view->assign('error', 'Invalid code, please try again.');
            $this->view->assign('secret', $secret);
            $this->view->assign('email', $email);
            $this->view->assign('authType', $authType);

            $this->setViewTemplate('Authenticator');
            return new HtmlResponse(TypeAssert::htmlString($this->view->render()));
        }
    }

    /**
     * function to save the user in miniorange_tfa_users table
     *
     */
    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $response
     */
    protected function saveUserInMiniorangeTfUsers(array $user, string $phone = '', array $response = [], string $email = ''): void
    {

        // Save username, email, active method, website, secret (if empty)
        $username = TypeAssert::stringValue($user['username'] ?? null);
        if ($email === '') {
            $email = TypeAssert::stringValue($user['email'] ?? $user['username'] ?? null);
        }

        $active_method = TypeAssert::stringValue($user['active_method'] ?? null);

        $website_id = $this->resolveFrontendWebsiteIdentifier();

        // If secret is empty, generate a new one
        $secret = TypeAssert::stringValue($user['secret'] ?? null);
        if ($secret === '') {
            $secret = bin2hex(random_bytes(16));
        }

        // Check if user exists in miniorange_tfa_users table
        $connection = GeneralUtility::makeInstance(\TYPO3\CMS\Core\Database\ConnectionPool::class)
            ->getConnectionForTable('miniorange_tfa_users');
        $existingUser = $connection->select(
            ['id'],
            'miniorange_tfa_users',
            ['username' => $username, 'website_id' => $website_id, 'is_admin' => 0]
        )->fetchAssociative();


        $transactionID = TypeAssert::stringValue($response['txId'] ?? null);
        $data = [
            'username' => $username,
            'email' => $email,
            'phone' => $phone,
            'active_method' => $active_method,
            'secret' => $secret,
            'website_id' => $website_id,
            'transactionId' => $transactionID,
            'is_admin' => 0,
        ];

        if (!$existingUser) {
            $limiter = GeneralUtility::makeInstance(AuthenticatorEnrollmentLimiter::class);
            $limiter->guardAndPersist(
                $connection,
                $username,
                0,
                $website_id,
                $active_method,
                static function () use ($connection, $data): void {
                    $connection->insert('miniorange_tfa_users', $data);
                }
            );
        }
    }

    /**
     * function to login the user in typo3 frontend
     *
     * @param array<string, mixed> $user
     */
    function loginFrontendUser(array $user): void
    {
        $request = $this->currentServerRequest();
        $frontendUser = GeneralUtility::makeInstance(\TYPO3\CMS\Frontend\Authentication\FrontendUserAuthentication::class);
        $frontendUser->start($request);
        $session = $frontendUser->createUserSession($user);
        $frontendUser->storeSessionData();
        setcookie("fe_typo_user", $session->getJwt(), 0, '/');

        // empty email,username,user_pid session
        $_SESSION['otp_email'] = null;
        $_SESSION['otp_username'] = null;
        $_SESSION['otp_user_pid'] = null;
        $_SESSION['otp_enabled_methods'] = null;
        $_SESSION['otp_method'] = null;
        $_SESSION['otp_transactionID'] = null;
        $_SESSION['otp_pending_method'] = null;
        $_SESSION['otp_email_added'] = null;
       
    }

    /**
     * function to verify the otp email
     *
     */
    public function verifyOtpEmailAction(): ResponseInterface
    {
        session_start();
        $email = $this->stringArgument('email', $this->sessionString('otp_email'));
        $username = $this->sessionString('otp_username');
        $this->storagePid = $this->sessionString('otp_user_pid');

        $enteredOtp = $this->stringArgument('otp');
        $phone = '';
        $email_display = $this->stringArgument('email_display');
        $transactionID = $this->stringArgument('transactionID', $this->sessionString('otp_transactionID'));
        $response = $this->decodeApiResponse(
            MoUtilities::verifyOtpViaMiniorange($username, $enteredOtp, 'email', $transactionID, $phone)
        );

        $email_added = $this->intArgument('email_added');

        if (($response['status'] ?? null) === 'SUCCESS') {
            $user = MoUtilities::fetchUserFromUsername($username, $this->storagePid);
            if (!is_array($user)) {
                $user = [];
            }
            $user['phone'] = $phone;
            $user['active_method'] = 'email';

            if ($email_added === 1) {
                $this->saveUserEmailInFeUser($email, $username);
            }
            try {
                $this->saveUserInMiniorangeTfUsers($user, $phone, $response, $email);
            } catch (AuthenticatorUserLimitReachedException) {
                $limitResponse = $this->loginWithoutTwoFactorAfterLimit();
                if ($limitResponse !== null) {
                    return $limitResponse;
                }
            }

            $this->loginFrontendUser($user);

            return $this->redirectToUri('/');
        }

        $otp_length = MoUtilities::getOtpLength();
        $this->view->assign('otp_length', $otp_length);
        $this->view->assign('email_display', $email_display);
        $this->view->assign('transactionID', $transactionID);
        $this->view->assign('enteredOtp', $enteredOtp);
        $this->view->assign('error', 'Invalid OTP, please try again.');
        $this->view->assign('email', $email);
        $this->view->assign('email_added', $email_added);
        $this->assignBackNavigation($email_added === 1 ? 'email_input' : null);
        $this->setViewTemplate('EmailVerify');

        return new HtmlResponse(TypeAssert::htmlString($this->view->render()));
    }

    /**
     * function to send the otp to the phone number via miniorange api
     *
     */
    public function sendOtpSmsAction(): ResponseInterface
    {
        $countryCode = $this->stringArgument('countryCode');
        $phone = $this->stringArgument('phone');

        session_start();
        $_SESSION['otp_countrycode'] = $countryCode;
        $fullPhone = $countryCode . $phone;
        $_SESSION['otp_phone'] = $fullPhone;

        if (strlen($fullPhone) < 8) {
            $this->view->assign('error', 'Invalid phone number');
            $this->assignBackNavigation(null);
            $this->setViewTemplate('SmsPhone');

            return new HtmlResponse(TypeAssert::htmlString($this->view->render()));
        }

        $last4Digits = substr($fullPhone, -4);
        $email = $this->sessionString('otp_email');
        $response = $this->decodeApiResponse(MoUtilities::sendOtpViaMiniorange($email, 'sms', $fullPhone));
        $status = strtolower(TypeAssert::stringValue($response['status'] ?? null));

        if ($status === 'success') {
            $this->view->assign('phone', $fullPhone);
            $this->view->assign('last4Digits', $last4Digits);
            $this->view->assign('transactionID', TypeAssert::stringValue($response['txId'] ?? null));
            $_SESSION['otp_transactionID'] = TypeAssert::stringValue($response['txId'] ?? null);
            $this->view->assign('otp_length', MoUtilities::getOtpLength());
            $this->assignBackNavigation('sms');
            $this->setViewTemplate('SmsVerify');
        } else {
            $errorMessage = TypeAssert::stringValue($response['message'] ?? null, 'Error sending OTP');
            if (MoUtilities::isTransactionLimitExceededMessage($errorMessage)) {
                $limitResponse = $this->continueLoginWithoutSmsTwoFactor();
                if ($limitResponse !== null) {
                    return $limitResponse;
                }
            }
            $this->view->assign('error', $errorMessage);
            $this->assignBackNavigation(null);
            $this->setViewTemplate('SmsPhone');
        }

        return new HtmlResponse(TypeAssert::htmlString($this->view->render()));
    }

    /**
     * function to verify the otp to the sms
     *
     */
    public function verifyOtpSmsAction(): ResponseInterface
    {
        session_start();
        $email = $this->sessionString('otp_email');
        $username = $this->sessionString('otp_username');
        $otp_length = MoUtilities::getOtpLength();
        $this->view->assign('otp_length', $otp_length);
        $this->storagePid = $this->sessionString('otp_user_pid');

        $enteredOtp = $this->stringArgument('otp');
        $phone = $this->stringArgument('phone', $this->sessionString('otp_phone'));
        $transactionID = $this->stringArgument('transactionID', $this->sessionString('otp_transactionID'));
        $response = $this->decodeApiResponse(
            MoUtilities::verifyOtpViaMiniorange($username, $enteredOtp, 'sms', $transactionID, $phone)
        );

        if (($response['status'] ?? null) === 'SUCCESS') {
            $user = MoUtilities::fetchUserFromUsername($username, $this->storagePid);
            if (!is_array($user)) {
                $user = [];
            }
            $request = $this->currentServerRequest();

            $user['phone'] = $phone;
            $user['active_method'] = 'sms';
            try {
                $this->saveUserInMiniorangeTfUsers($user, $phone, $response);
            } catch (AuthenticatorUserLimitReachedException) {
                $limitResponse = $this->loginWithoutTwoFactorAfterLimit();
                if ($limitResponse !== null) {
                    return $limitResponse;
                }
            }

            $frontendUser = GeneralUtility::makeInstance(FrontendUserAuthentication::class);
            $frontendUser->start($request);
            $session = $frontendUser->createUserSession($user);
            $frontendUser->storeSessionData();
            setcookie('fe_typo_user', $session->getJwt(), 0, '/');

            return $this->redirectToUri('/');
        }

        $this->view->assign('error', 'Invalid OTP, please try again.');
        $this->view->assign('phone', $phone);
        $this->view->assign('email', $email);
        $this->view->assign('last4Digits', strlen($phone) >= 4 ? substr($phone, -4) : '');
        $this->view->assign('transactionID', $transactionID);
        $this->view->assign('enteredOtp', $enteredOtp);
        $this->view->assign('otp_length', $otp_length);
        $this->assignBackNavigation('sms');
        $this->setViewTemplate('SmsVerify');

        return new HtmlResponse(TypeAssert::htmlString($this->view->render()));
    }

    /**
     * function to setup the email and sms
     *
     */
    public function setupEmailSmsAction(): ResponseInterface
    {
        session_start();
        $countryCode = $this->stringArgument('countryCode');
        $phone = $this->stringArgument('phone');
        $email = $this->stringArgument('email', $this->sessionString('otp_email'));
        $_SESSION['otp_countrycode'] = $countryCode;
        $fullPhone = $countryCode . $phone;
        $_SESSION['otp_phone'] = $fullPhone;

        if (strlen($fullPhone) < 8) {
            $this->view->assign('error', 'Invalid phone number');
            $this->view->assign('countryCode', $countryCode);
            $this->view->assign('phone', $phone);
            $this->view->assign('email', $email);
            $this->setViewTemplate('EmailSmsSetup');

            return new HtmlResponse(TypeAssert::htmlString($this->view->render()));
        }

        $last4Digits = substr($fullPhone, -4);
        $response = $this->decodeApiResponse(MoUtilities::sendOtpViaMiniorange($email, 'sms_email', $fullPhone));
        $status = strtolower(TypeAssert::stringValue($response['status'] ?? null));

        if ($status === 'success') {
            $this->view->assign('phone', $fullPhone);
            $this->view->assign('last4Digits', $last4Digits);
            $this->view->assign('transactionID', TypeAssert::stringValue($response['txId'] ?? null));
            $_SESSION['otp_transactionID'] = TypeAssert::stringValue($response['txId'] ?? null);
            $this->view->assign('otp_length', MoUtilities::getOtpLength());
            $this->view->assign('email', $email);
            $this->setViewTemplate('EmailSmsVerify');
        } else {
            $errorMessage = TypeAssert::stringValue($response['message'] ?? null, 'Error sending OTP');
            if (MoUtilities::isTransactionLimitExceededMessage($errorMessage)) {
                $limitResponse = $this->continueLoginWithoutSmsTwoFactor();
                if ($limitResponse !== null) {
                    return $limitResponse;
                }
            }
            $this->view->assign('error', $errorMessage);
            $this->view->assign('countryCode', $countryCode);
            $this->view->assign('phone', $phone);
            $this->view->assign('email', $email);
            $this->setViewTemplate('EmailSmsSetup');
        }

        return new HtmlResponse(TypeAssert::htmlString($this->view->render()));
    }

    /**
     * function to verify the otp to the email and sms
     *
     */
    public function verifyEmailSmsAction(): ResponseInterface
    {
        session_start();
        $email = $this->sessionString('otp_email');
        $username = $this->sessionString('otp_username');
        $otp_length = MoUtilities::getOtpLength();
        $this->view->assign('otp_length', $otp_length);
        $this->storagePid = $this->sessionString('otp_user_pid');

        $enteredOtp = $this->stringArgument('otp');
        $phone = $this->stringArgument('phone', $this->sessionString('otp_phone'));
        $transactionID = $this->stringArgument('transactionID', $this->sessionString('otp_transactionID'));
        $response = $this->decodeApiResponse(
            MoUtilities::verifyOtpViaMiniorange($username, $enteredOtp, 'sms', $transactionID, $phone)
        );

        if (($response['status'] ?? null) === 'SUCCESS') {
            $user = MoUtilities::fetchUserFromUsername($username, $this->storagePid);
            if (!is_array($user)) {
                $user = [];
            }
            $request = $this->currentServerRequest();

            $user['phone'] = $phone;
            $user['active_method'] = 'sms_email';
            try {
                $this->saveUserInMiniorangeTfUsers($user, $phone, $response);
            } catch (AuthenticatorUserLimitReachedException) {
                $limitResponse = $this->loginWithoutTwoFactorAfterLimit();
                if ($limitResponse !== null) {
                    return $limitResponse;
                }
            }

            $frontendUser = GeneralUtility::makeInstance(FrontendUserAuthentication::class);
            $frontendUser->start($request);
            $session = $frontendUser->createUserSession($user);
            $frontendUser->storeSessionData();
            setcookie('fe_typo_user', $session->getJwt(), 0, '/');

            return $this->redirectToUri('/');
        }

        $this->view->assign('error', 'Invalid OTP, please try again.');
        $this->view->assign('phone', $phone);
        $this->view->assign('email', $email);
        $this->view->assign('last4Digits', strlen($phone) >= 4 ? substr($phone, -4) : '');
        $this->view->assign('transactionID', $transactionID);
        $this->view->assign('enteredOtp', $enteredOtp);
        $this->view->assign('otp_length', $otp_length);
        $this->setViewTemplate('EmailSmsVerify');

        return new HtmlResponse(TypeAssert::htmlString($this->view->render()));
    }

    /**
     * function to verify the otp to the existing user
     *
     */
    public function verifyExistingUserAction(): ResponseInterface
    {
        $enteredOtp = $this->stringArgument('otp');
        $username = $this->stringArgument('username');
        $email = $this->stringArgument('email');
        $activeMethod = $this->stringArgument('active_method');
        $transactionID = $this->stringArgument('transactionID');
        $secret = $this->stringArgument('secret');
        $phone = $this->stringArgument('phone');

        if (in_array($activeMethod, ['google', 'microsoft', 'Microsoft Authenticator', 'Google Authenticator'], true)) {
            $response = $this->decodeApiResponse(MoUtilities::verifyGauthCode($enteredOtp, $username, $secret));
        } else {
            $response = $this->decodeApiResponse(
                MoUtilities::verifyOtpViaMiniorange($username, $enteredOtp, $activeMethod, $transactionID, $phone)
            );
        }

        session_start();
        $this->storagePid = $this->sessionString('otp_user_pid', $this->stringArgument('user_pid'));

        if (($response['status'] ?? null) === 'SUCCESS') {
            $user = MoUtilities::fetchUserFromUsername($username, $this->storagePid);

            if (is_array($user)) {
                $this->loginFrontendUser($user);
                unset($_SESSION['existing_2fa_user']);

                return $this->redirectToUri('/');
            }

            $this->addFlashMessage('User not found. Please try again.', 'Error');

            return $this->redirectToUri('/');
        }

        $otp_length = MoUtilities::getOtpLength();
        if (in_array($activeMethod, ['google', 'microsoft', 'Microsoft Authenticator', 'Google Authenticator'], true)) {
            $otp_length = 6;
        }
        $this->view->assign('error', 'Invalid verification code, please try again.');
        $this->view->assign('otp_length', $otp_length);
        $this->view->assign('username', $username);
        $this->view->assign('phone', $phone);
        $this->view->assign('transactionID', $transactionID);
        $this->view->assign('enteredOtp', $enteredOtp);
        $this->view->assign('email', $email);
        $this->view->assign('active_method', $activeMethod);
        $this->view->assign('secret', $secret);
        $this->setViewTemplate('ExistingUserVerify');

        return new HtmlResponse(TypeAssert::htmlString($this->view->render()));
    }

    /**
     * function to check if email is already in fe_users table or not it must be unique
     *
     */
    /**
     * @return array<string, mixed>|false
     */
    private function checkEmailInFeUsers(string $email): array|false
    {
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
        ->getQueryBuilderForTable('fe_users');
        $result = $queryBuilder
            ->select('*')
            ->from('fe_users')
            ->where(
                $queryBuilder->expr()->eq('username', $queryBuilder->createNamedParameter($email))
            )
            ->executeQuery()
            ->fetchAssociative();
        return $result;
    }

    /**
     * Display the email OTP form without sending a new code (PRG target).
     */
    public function emailVerifyAction(): ResponseInterface
    {
        session_start();
        $email = $this->sessionString('otp_email');
        if (!$this->pendingOtpMatches('email', $email)) {
            return $this->redirect('show');
        }

        return $this->renderEmailVerificationPage($email);
    }

    /**
     * Process email input from user when no email is found
     *
     * @return ResponseInterface
     */
    public function processEmailInputAction(): ResponseInterface
    {
        session_start();

        $email = $this->stringArgument('email', $this->sessionString('otp_email'));

        // GET/reload of this action must not send another OTP.
        if (strtoupper($this->currentServerRequest()->getMethod()) !== 'POST') {
            if ($this->pendingOtpMatches('email', $email)) {
                return $this->redirect('emailVerify');
            }
        }

        $result = $this->checkEmailInFeUsers($email);

        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $result !== false) {
            $user_pid = $this->stringArgument('user_pid');
            $this->view->assign('user_pid', $user_pid);
            $this->view->assign('email', $email);
            $this->view->assign('otp_length', MoUtilities::getOtpLength());
            $this->view->assign('email_added', $this->intArgument('email_added'));
            $this->assignBackNavigation(null);
            if ($result !== false) {
                $this->view->assign('error', 'A user with the same email already exists. Please enter a unique email address.');
                $this->setViewTemplate('EmailInput');
            } else {
                $this->view->assign('error', 'Please enter a valid email address.');
                $this->setViewTemplate('EmailInput');
            }

            return new HtmlResponse(TypeAssert::htmlString($this->view->render()));
        }

        $_SESSION['otp_email'] = $email;
        $user_pid = $this->stringArgument('user_pid');
        $this->view->assign('user_pid', $user_pid);
        if ($user_pid !== '') {
            $_SESSION['otp_user_pid'] = $user_pid;
        }

        $prepareResponse = $this->sendEmailOtpIfNeeded($email, 1);
        if ($prepareResponse !== null) {
            return $prepareResponse;
        }

        return $this->redirect('emailVerify');
    }

    /**
     * function to save the user email in fe_user table
     *
     */
    private function saveUserEmailInFeUser(string $email, string $username): int
    {
        try {
            $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
                ->getQueryBuilderForTable('fe_user');
            
            $result = $queryBuilder
                ->update('fe_users')
                ->set('email', $email)
                ->where(
                    $queryBuilder->expr()->eq('username', $queryBuilder->createNamedParameter($username))
                )
                ->executeStatement();
            
            // Log the result for debugging (optional)
            // \TYPO3\CMS\Core\Utility\GeneralUtility::devLog('Email update result: ' . $result, 'mfa');
            
            return $result;
        } catch (\Exception $e) {
            GeneralUtility::makeInstance(\TYPO3\CMS\Core\Log\LogManager::class)
                ->getLogger(__CLASS__)
                ->error('Error updating user email: ' . $e->getMessage());

            throw $e;
        }
    }

    private function assignAuthenticatorEnrollmentView(string $authType, ?string $email): void
    {
        $this->view->assign('email', $email);
        $this->view->assign('authType', $authType);
        $this->setViewTemplate('Authenticator');
        $this->view->assign('secret', $this->generateAuthenticatorSecret());
        $this->view->assign('showAuthenticatorSetup', true);
        $this->view->assign('enrollmentBlocked', false);
    }

    private function redirectIfTwoFactorSetupBlocked(): ?ResponseInterface
    {
        if (!$this->isFrontendTwoFactorSetupBlocked()) {
            return null;
        }

        return $this->loginWithoutTwoFactorChallenge();
    }

    private function isFrontendTwoFactorSetupBlocked(): bool
    {
        if (TypeAssert::stringValue($_SESSION['otp_username'] ?? null) === '') {
            return false;
        }

        $connection = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable(Constants::TABLE_TFA_USERS);
        $limiter = GeneralUtility::makeInstance(AuthenticatorEnrollmentLimiter::class);

        return $limiter->isTwoFactorLimitReached($connection);
    }

    private function continueLoginWithoutSmsTwoFactor(): ?ResponseInterface
    {
        $username = TypeAssert::stringValue($_SESSION['otp_username'] ?? null);
        $this->storagePid = TypeAssert::stringValue($_SESSION['otp_user_pid'] ?? null);
        $user = MoUtilities::fetchUserFromUsername($username, $this->storagePid);
        if (!is_array($user) || TypeAssert::stringValue($user['username'] ?? null) === '') {
            return null;
        }

        MoUtilities::storeFrontendAuthenticatorLimitMessage(
            'The 2FA transaction limit has been exceeded. Please contact your administrator.'
        );
        $this->loginFrontendUser($user);

        return $this->redirectToUri('/');
    }

    private function loginWithoutTwoFactorAfterLimit(): ?ResponseInterface
    {
        $username = TypeAssert::stringValue($_SESSION['otp_username'] ?? null);
        $this->storagePid = TypeAssert::stringValue($_SESSION['otp_user_pid'] ?? null);
        $user = MoUtilities::fetchUserFromUsername($username, $this->storagePid);
        if (!is_array($user) || TypeAssert::stringValue($user['username'] ?? null) === '') {
            return null;
        }

        MoUtilities::storeFrontendAuthenticatorLimitMessage();
        $this->loginFrontendUser($user);

        return $this->redirectToUri('/');
    }

    private function loginWithoutTwoFactorChallenge(): ResponseInterface
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        $limitResponse = $this->loginWithoutTwoFactorAfterLimit();
        if ($limitResponse !== null) {
            return $limitResponse;
        }

        $this->view->assign('error', 'Unable to complete login. Please try again.');
        $this->setViewTemplate('Show');

        return $this->htmlResponse();
    }

    private function resolveFrontendWebsiteIdentifier(): string
    {
        $request = TypeAssert::serverRequest($GLOBALS['TYPO3_REQUEST'] ?? null);
        if ($request instanceof ServerRequestInterface) {
            $site = $request->getAttribute('site');
            if ($site instanceof Site) {
                return $site->getIdentifier();
            }
        }

        $tsfe = $GLOBALS['TSFE'] ?? null;
        $currentPageId = 1;
        if ($tsfe instanceof \TYPO3\CMS\Frontend\Controller\TypoScriptFrontendController) {
            $currentPageId = TypeAssert::intValue($tsfe->id, 1);
        }
        $siteFinder = GeneralUtility::makeInstance(SiteFinder::class);

        return $siteFinder->getSiteByPageId($currentPageId)->getIdentifier();
    }

    private function currentServerRequest(): ServerRequestInterface
    {
        $global = $GLOBALS['TYPO3_REQUEST'] ?? null;
        if ($global instanceof ServerRequestInterface) {
            return $global;
        }

        return $this->request;
    }

    private function setViewTemplate(string $templateName): void
    {
        $view = $this->view;
        if ($view instanceof FluidViewAdapter || $view instanceof AbstractTemplateView) {
            $view->getRenderingContext()->setControllerAction($templateName);
        }
    }

    /**
     * Whether more than one 2FA method is enabled for the current FE challenge.
     * Mirrors the same normalization/condition showAction() uses to decide
     * whether to render the method-selection page.
     */
    private function hasMultipleEnabledMethods(): bool
    {
        $enabledMethods = $_SESSION['otp_enabled_methods'] ?? null;
        if (is_string($enabledMethods)) {
            $enabledMethods = explode(',', $enabledMethods);
        }

        return is_array($enabledMethods) && count($enabledMethods) > 1;
    }

    /**
    * Assigns the Back-link target for the current page.
    * If $singleMethodTarget is set, it returns to the previous step.
    * Otherwise, Back goes to the method-selection page for multiple methods,
    * or to the login page when only one method is enabled.
    */
    private function assignBackNavigation(?string $singleMethodTarget): void
    {
        if ($singleMethodTarget !== null) {
            $this->view->assign('backToLogin', false);
            $this->view->assign('backMethod', $singleMethodTarget);

            return;
        }

        if ($this->hasMultipleEnabledMethods()) {
            $this->view->assign('backToLogin', false);
            $this->view->assign('backMethod', 'select');

            return;
        }

        $this->view->assign('backToLogin', true);
        $this->view->assign('backMethod', '');
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeApiResponse(mixed $response): array
    {
        return TypeAssert::decodeJsonArray($response);
    }

    private function stringArgument(string $name, string $default = ''): string
    {
        if (!$this->request->hasArgument($name)) {
            return $default;
        }

        return TypeAssert::stringValue($this->request->getArgument($name), $default);
    }

    private function intArgument(string $name, int $default = 0): int
    {
        if (!$this->request->hasArgument($name)) {
            return $default;
        }

        return TypeAssert::intValue($this->request->getArgument($name), $default);
    }

    private function sessionString(string $key, string $default = ''): string
    {
        return TypeAssert::stringValue($_SESSION[$key] ?? null, $default);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function existingUserFromSession(): ?array
    {
        $existingUser = $_SESSION['existing_2fa_user'] ?? null;

        return is_array($existingUser) ? TypeAssert::stringKeyedArray($existingUser) : null;
    }

    private function maskEmail(string $email): string
    {
        $atPos = strpos($email, '@');
        if ($atPos === false) {
            return $email;
        }

        return substr($email, 0, 1) . '******' . substr($email, $atPos);
    }

    /**
     * Sends an email OTP unless a pending transaction already exists for this address.
     * Returns a response on validation/API failure; null when the OTP is ready to display.
     */
    private function sendEmailOtpIfNeeded(string $email, int $emailAdded = 0, bool $force = false): ?ResponseInterface
    {
        $this->view->assign('email', $email);
        $this->view->assign('otp_length', MoUtilities::getOtpLength());
        $this->view->assign('user_pid', $this->sessionString('otp_user_pid'));

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->assignBackNavigation(null);
            $this->setViewTemplate('EmailInput');

            return $this->htmlResponse();
        }

        if (!$force && $this->pendingOtpMatches('email', $email)) {
            return null;
        }

        $response = $this->decodeApiResponse(MoUtilities::sendOtpViaMiniorange($email, 'email', ''));
        if (($response['status'] ?? null) !== 'SUCCESS') {
            $this->addFlashMessage(
                TypeAssert::stringValue($response['message'] ?? null, 'Error sending OTP'),
                'Error'
            );

            return $this->redirectToUri('/');
        }

        $this->storePendingOtp(
            'email',
            TypeAssert::stringValue($response['txId'] ?? null),
            $email,
            $emailAdded
        );

        return null;
    }

    private function renderEmailVerificationPage(string $email): ResponseInterface
    {
        $this->view->assign('email', $email);
        $this->view->assign('otp_length', MoUtilities::getOtpLength());
        $this->view->assign('email_display', $this->maskEmail($email));
        $this->view->assign('showEmailVerification', true);
        $this->view->assign('transactionID', $this->sessionString('otp_transactionID'));
        $emailAdded = TypeAssert::intValue($_SESSION['otp_email_added'] ?? 0);
        $this->view->assign('email_added', $emailAdded);
        $this->view->assign('user_pid', $this->sessionString('otp_user_pid'));
        $this->assignBackNavigation($emailAdded === 1 ? 'email_input' : null);
        $this->setViewTemplate('EmailVerify');

        return $this->htmlResponse();
    }

    private function pendingOtpMatches(string $method, string $email): bool
    {
        if ($email === '') {
            return false;
        }

        return $this->sessionString('otp_transactionID') !== ''
            && strtolower($this->sessionString('otp_pending_method')) === strtolower($method)
            && strcasecmp($this->sessionString('otp_email'), $email) === 0;
    }

    private function storePendingOtp(string $method, string $transactionID, string $email, int $emailAdded = 0): void
    {
        $_SESSION['otp_email'] = $email;
        $_SESSION['otp_transactionID'] = $transactionID;
        $_SESSION['otp_pending_method'] = $method;
        $_SESSION['otp_email_added'] = $emailAdded;
        if (strtolower($method) === 'email') {
            $_SESSION['otp_method'] = 'email';
        }
    }
}