<?php

declare(strict_types=1);

namespace Miniorange\Otp\Services;

use Miniorange\Otp\Helper\TypeAssert;
use TYPO3\CMS\Core\Authentication\AuthenticationService;
use TYPO3\CMS\Core\Crypto\PasswordHashing\PasswordHashFactory;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class Backend2FAUserAuthenticationService extends AuthenticationService
{
    public const SERVICE_KEY = 'Miniorange\Otp\Services\Backend2FAUserAuthenticationService';

    /** @var array<string, mixed> */
    protected array $loginData = [];

    public function init(): bool
    {
        return parent::init();
    }

    /**
     * @param string $mode The subType (e.g. 'getUserBE')
     * @param array<string, mixed> $loginData Username/Password from form
     * @param array<string, mixed> $authInfo Additional info
     */
    public function initAuth($mode, $loginData, $authInfo, $pObj): bool
    {
        parent::initAuth($mode, $loginData, $authInfo, $pObj);

        $this->loginData = TypeAssert::stringKeyedArray($loginData);

        // We only want to handle Backend Login ('getUserBE')
        return $mode === 'getUserBE';
    }

    public function getServiceKey(): string
    {
        return self::SERVICE_KEY;
    }

    /**
     * Identify the user and validate password.
     * Returns 200 on success to let TYPO3 start the session.
     *
     * @param array<string, mixed> $user
     */
    public function authUser(array $user): int
    {
        if ($user === []) {
            return 100; // User not found
        }

        $passwordEntered = isset($this->loginData['uident']) && is_string($this->loginData['uident'])
            ? trim($this->loginData['uident'])
            : '';

        $storedPassword = $user['password'] ?? '';
        if (!is_string($storedPassword)) {
            return 100;
        }

        // Validate Password
        // Note: TYPO3 Backend uses 'BE' mode for password hashing
        $hashFactory = GeneralUtility::makeInstance(PasswordHashFactory::class);
        $passwordValid = $hashFactory->get($storedPassword, 'BE')->checkPassword(
            $passwordEntered,
            $storedPassword
        );

        if ($passwordValid) {
            // Return Success (200)
            return 200;
        }

        return 100; // Authentication failed
    }

    /**
     * @return array<string, mixed>|false
     */
    public function getUser(): array|false
    {
        $uname = isset($this->loginData['uname']) && is_string($this->loginData['uname'])
            ? trim($this->loginData['uname'])
            : '';

        if ($uname === '') {
            return false;
        }

        $qb = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('be_users');
        $row = $qb->select('*')
            ->from('be_users')
            ->where(
                $qb->expr()->eq('username', $qb->createNamedParameter($uname)),
                $qb->expr()->eq('disable', 0),
                $qb->expr()->eq('deleted', 0)
            )
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        return is_array($row) ? $row : false;
    }
}
