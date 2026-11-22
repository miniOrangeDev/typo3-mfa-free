<?php

declare(strict_types=1);

namespace Miniorange\Otp\Services;

use Miniorange\Otp\Domain\Repository\Site\SiteRepository;
use Miniorange\Otp\Helper\Constants;
use Miniorange\Otp\Helper\MoUtilities;
use Miniorange\Otp\Helper\TypeAssert;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Crypto\PasswordHashing\PasswordHashFactory;
use TYPO3\CMS\Core\Information\Typo3Version;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Frontend\Authentication\FrontendUserAuthentication;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Site\Entity\Site;

class Frontend2FAUserAuthenticationService extends FrontendUserAuthentication
{
    protected string $email_column = 'email';

    /** @var array<string, mixed> */
    protected array $loginData = [];

    public mixed $info = null;

    public const SERVICE_KEY = 'Miniorange\Otp\Services\Frontend2FAUserAuthenticationService';

    public function init(): bool
    {
        return true;
    }

    /**
     * @param array<string, mixed> $loginData
     */
    public function initAuth(string $subType, array $loginData): bool
    {
        $this->loginData = $loginData;

        return $subType === 'getUserFE';
    }

    public function getServiceKey(): string
    {
        return self::SERVICE_KEY;
    }

    /**
     * @param array<string, mixed> $user
     * @param list<string>|string|null $enabledMethods
     */
    private function findAndRedirectToPluginPage(
        string $pluginValue,
        array $user,
        ?string $email = null,
        ?string $method = null,
        array|string|null $enabledMethods = null
    ): void {
        session_start();

        $_SESSION['otp_email'] = $email;
        $_COOKIE['otp_email'] = $email;

        if ($method !== null) {
            $_SESSION['otp_method'] = $method;
        }
        if ($enabledMethods !== null) {
            $_SESSION['otp_enabled_methods'] = $enabledMethods;
        }

        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable('tt_content');

        $result = $queryBuilder
            ->select('tt_content.pid', 'tt_content.uid', 'pages.slug')
            ->from('tt_content')
            ->join(
                'tt_content',
                'pages',
                'pages',
                $queryBuilder->expr()->eq('pages.uid', $queryBuilder->quoteIdentifier('tt_content.pid'))
            )
            ->where(
                $queryBuilder->expr()->or(
                    $queryBuilder->expr()->and(
                        $queryBuilder->expr()->eq('CType', $queryBuilder->createNamedParameter('list')),
                        $queryBuilder->expr()->eq('list_type', $queryBuilder->createNamedParameter($pluginValue))
                    ),
                    $queryBuilder->expr()->eq('CType', $queryBuilder->createNamedParameter($pluginValue))
                )
            )
            ->setMaxResults(1)
            ->executeQuery();

        $row = $result->fetchAssociative();
        if (!is_array($row)) {
            header('HTTP/1.1 404 Not Found');
            echo '2FA authentication page is not configured. Create a page with the plugin "MiniOrange OTP - 2FA Verification Page" and enable it.';
            exit;
        }

        $siteFinder = GeneralUtility::makeInstance(SiteFinder::class);
        $pid = TypeAssert::intValue($row['pid'] ?? null);
        $site = $siteFinder->getSiteByPageId($pid);

        $params = ['email' => $email];
        if ($method !== null) {
            $params['method'] = $method;
        }

        if (is_array($enabledMethods)) {
            $enabledMethods = implode(',', $enabledMethods);
        }
        $params['user_pid'] = $user['pid'];

        $uid = TypeAssert::intValue($row['uid'] ?? null);
        $uri = $site->getRouter()->generateUri($pid, $params)->withFragment('c' . $uid);

        $_SESSION['otp_email'] = $email;
        $_SESSION['otp_user_pid'] = $user['pid'];
        $_SESSION['otp_method'] = $method;
        $_SESSION['otp_username'] = $user['username'];
        $_SESSION['otp_enabled_methods'] = $enabledMethods;

        header('Location: ' . (string)$uri);
        exit;
    }

    /**
     * @param array<string, mixed> $user
     */
    public function authUser(array $user): int
    {
        if ($user === []) {
            $this->setLoginError('Invalid email or username. Please check your credentials and try again.');

            return -50;
        }

        $passwordEntered = TypeAssert::stringValue($this->loginData['uident'] ?? null);
        $storedPassword = TypeAssert::stringValue($user[$this->userident_column] ?? null);

        if ($this->checkPassword(trim($passwordEntered), $storedPassword)) {
            $userEmail = TypeAssert::stringValue($user['email'] ?? null);
            $twofa_enabled = $this->is2faEnabled($userEmail);

            if (is_array($twofa_enabled) && TypeAssert::intValue($twofa_enabled['customer_2fa'] ?? null) === 1) {
                $row = $this->getTfaUserRow($user);

                if (is_array($row) && TypeAssert::intValue($row['disable_2fa'] ?? null) === 1) {
                    return 250;
                }

                if ($this->isTwoFactorUserLimitReached()) {
                    MoUtilities::storeFrontendAuthenticatorLimitMessage();

                    return 250;
                }

                if ($row !== null) {
                    return $this->handleExisting2FAUser($user, $row);
                }

                return $this->handleNew2FAUser($user, $twofa_enabled);
            }

            return 250;
        }

        $this->setLoginError('Invalid password. Please check your credentials and try again.');

        return -50;
    }

    private function setLoginError(string $message): void
    {
        if (!isset($_SESSION)) {
            session_start();
        }
        $_SESSION['login_error'] = $message;

        $request = TypeAssert::serverRequest($GLOBALS['TYPO3_REQUEST'] ?? null);
        $frontendUser = $request?->getAttribute('frontend.user');
        if ($frontendUser instanceof FrontendUserAuthentication) {
            $frontendUser->setAndSaveSessionData('login_error', $message);
        }
    }

    private function getCurrentWebsiteId(): string
    {
        $request = $GLOBALS['TYPO3_REQUEST'] ?? null;
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

    /**
     * @return mixed
     */
    public static function fetchFromCustomer(string $col): mixed
    {
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable(Constants::TABLE_CUSTOMER);
        $typo3Version = (new Typo3Version())->getMajorVersion();
        if ($typo3Version >= 12) {
            $variable = $queryBuilder->select($col)
                ->from(Constants::TABLE_CUSTOMER)
                ->where($queryBuilder->expr()->eq('id', $queryBuilder->createNamedParameter(1, Connection::PARAM_INT)))
                ->executeQuery()
                ->fetchAssociative();
        } else {
            $variable = $queryBuilder->select($col)
                ->from(Constants::TABLE_CUSTOMER)
                ->where($queryBuilder->expr()->eq('id', $queryBuilder->createNamedParameter(1, Connection::PARAM_INT)))
                ->executeQuery()
                ->fetchAssociative();
        }

        return is_array($variable) ? ($variable[$col] ?? null) : $variable;
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $twofa_enabled
     */
    private function handleNew2FAUser(array $user, array $twofa_enabled): int
    {
        $enabledMethods = $this->getEnabled2FAMethods($user, $twofa_enabled);

        if (count($enabledMethods) === 1) {
            $this->redirectTo2FAMethod($user, $enabledMethods[0]);
        } else {
            $this->redirectTo2FAMethodSelectionForMultipleMethods($user, $enabledMethods);
        }

        return 100;
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $twofa_enabled
     * @return list<string>
     */
    private function getEnabled2FAMethods(array $user, array $twofa_enabled): array
    {
        $methods = [];
        $customerMethod = TypeAssert::stringValue($twofa_enabled['customer_2fa_method'] ?? null);

        if ($customerMethod !== ''
            && (strtoupper($customerMethod) === 'EMAIL' || strtoupper($customerMethod) === 'SMS_EMAIL')
        ) {
            if (TypeAssert::stringValue($user['email'] ?? null) === '') {
                $user['email'] = TypeAssert::stringValue($user['username'] ?? null);
            }
            $methods[] = strtoupper(trim($customerMethod));
        }

        if ($customerMethod !== '' && strtoupper($customerMethod) === 'SMS') {
            $methods[] = 'SMS';
        }

        if ($customerMethod !== '' && strtoupper($customerMethod) === 'MICROSOFT') {
            $methods[] = 'MICROSOFT';
        }

        if ($customerMethod !== '' && strtoupper($customerMethod) === 'GOOGLE') {
            $methods[] = 'GOOGLE';
        }

        if ($customerMethod !== '' && str_contains($customerMethod, ',')) {
            foreach (explode(',', $customerMethod) as $method) {
                $method = trim(strtoupper($method));
                if (in_array($method, ['EMAIL', 'SMS_EMAIL', 'SMS', 'MICROSOFT', 'GOOGLE'], true)) {
                    if (in_array($method, ['EMAIL', 'SMS_EMAIL'], true) && TypeAssert::stringValue($user['email'] ?? null) === '') {
                        $user['email'] = TypeAssert::stringValue($user['username'] ?? null);
                    }
                    $methods[] = $method;
                }
            }
        }

        return $methods;
    }

    /**
     * @param array<string, mixed> $user
     */
    private function redirectTo2FAMethod(array $user, string $method): void
    {
        $email = TypeAssert::stringValue($user['email'] ?? null);
        if ($email === '') {
            $email = TypeAssert::stringValue($user['username'] ?? null);
        }

        $this->findAndRedirectToPluginPage('mfa_otpauthentication', $user, $email, $method);
    }

    /**
     * @param array<string, mixed> $user
     * @param list<string> $enabledMethods
     */
    private function redirectTo2FAMethodSelectionForMultipleMethods(array $user, array $enabledMethods): void
    {
        $email = TypeAssert::stringValue($user['email'] ?? null);
        if ($email === '') {
            $email = TypeAssert::stringValue($user['username'] ?? null);
        }

        $methods = implode(',', $enabledMethods);
        $this->findAndRedirectToPluginPage('mfa_otpauthentication', $user, $email, $methods, $enabledMethods);
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $row
     */
    private function handleExisting2FAUser(array $user, array $row): int
    {
        $method = TypeAssert::stringValue($row['active_method'] ?? null);
        $email = TypeAssert::stringValue($row['email'] ?? null);
        $username = TypeAssert::stringValue($row['username'] ?? null);

        session_start();
        $_SESSION['existing_2fa_user'] = [
            'username' => $username,
            'email' => $email,
            'active_method' => $method,
            'user_pid' => $user['pid'],
            'phone' => TypeAssert::stringValue($row['phone'] ?? null),
            'secret' => TypeAssert::stringValue($row['secret'] ?? null),
        ];

        $this->findAndRedirectToPluginPage('mfa_otpauthentication', $user, $email, 'existing_user', $method);

        return 100;
    }

    /**
     * @param array<string, mixed> $user
     * @return array<string, mixed>|null
     */
    private function getTfaUserRow(array $user, ?int $pid = null): ?array
    {
        if (TypeAssert::stringValue($user['username'] ?? null) === '') {
            return null;
        }

        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable('miniorange_tfa_users');

        $expr = $queryBuilder->expr();
        $conditions = [
            $expr->eq('username', $queryBuilder->createNamedParameter($user['username'])),
            $expr->eq('is_admin', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
        ];

        $websiteId = $this->getCurrentWebsiteId();
        if ($websiteId !== '') {
            $conditions[] = $expr->eq('website_id', $queryBuilder->createNamedParameter($websiteId));
        }

        $row = $queryBuilder
            ->select('*')
            ->from('miniorange_tfa_users')
            ->where(...$conditions)
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        return is_array($row) ? $row : null;
    }

    /**
     * @return array<string, mixed>|false
     */
    private function is2faEnabled(string $user_email): array|false
    {
        if (!MoUtilities::isCustomerLoggedIn()) {
            return false;
        }

        $websiteId = $this->getCurrentWebsiteId();
        $siteRepository = GeneralUtility::makeInstance(SiteRepository::class);
        $mainWebsiteId = $siteRepository->findMainIdentifier();

        if ($mainWebsiteId === null || $websiteId !== $mainWebsiteId) {
            return false;
        }

        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable('miniorange_tfa_settings');

        $row = $queryBuilder
            ->select(
                'customer_2fa',
                'customer_2fa_method',
                'customer_2fa_group',
                'customer_2fa_website_id'
            )
            ->from('miniorange_tfa_settings')
            ->where(
                $queryBuilder->expr()->eq('customer_2fa', $queryBuilder->createNamedParameter(1)),
                $queryBuilder->expr()->eq(
                    'customer_2fa_website_id',
                    $queryBuilder->createNamedParameter($mainWebsiteId)
                )
            )
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        if (!is_array($row) || empty($row['customer_2fa'])) {
            error_log('2FA not enabled for this customer');

            return false;
        }

        if (TypeAssert::stringValue($row['customer_2fa_method'] ?? null) === '') {
            error_log('2FA method not set for this customer');

            return false;
        }

        error_log('2FA enabled for this customer with method: ' . TypeAssert::stringValue($row['customer_2fa_method'] ?? null));

        return $row;
    }

    /**
     * @return array<string, mixed>|false
     */
    public function getUser(): array|false
    {
        $uname = trim(TypeAssert::stringValue($this->loginData['uname'] ?? null));
        if ($uname === '') {
            return false;
        }

        $qb = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable($this->user_table);
        $p_uname = $qb->createNamedParameter($uname);
        $query = $qb->select('*')->from($this->user_table);
        $query->where($qb->expr()->eq($this->username_column, $p_uname));
        $query->setMaxResults(2);

        $rows = $query->executeQuery()->fetchAllAssociative();

        if (count($rows) === 1) {
            return $rows[0];
        }

        return false;
    }

    private function checkPassword(string $password, string $passwordHash): bool
    {
        return GeneralUtility::makeInstance(PasswordHashFactory::class)
            ->get($passwordHash, $this->loginType)
            ->checkPassword($password, $passwordHash);
    }

    private function isTwoFactorUserLimitReached(): bool
    {
        $connection = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable(Constants::TABLE_TFA_USERS);
        $limiter = GeneralUtility::makeInstance(AuthenticatorEnrollmentLimiter::class);

        return $limiter->isTwoFactorLimitReached($connection);
    }
}
