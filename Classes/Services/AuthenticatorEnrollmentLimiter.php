<?php

declare(strict_types=1);

namespace Miniorange\Otp\Services;

use Miniorange\Otp\Exception\AuthenticatorUserLimitReachedException;
use Miniorange\Otp\Helper\AESEncryption;
use Miniorange\Otp\Helper\Constants;
use TYPO3\CMS\Core\Database\Connection;

/**
 * Enforces the free-plan global 2FA limit of 10 users.
 *
 * Email, SMS, Google Authenticator and Microsoft Authenticator all share
 * miniorange_tfa_customer.user_count. Backend and frontend users share the
 * same counter. The value is stored encrypted.
 *
 * When user_count is at or above the cap, 2FA is skipped for every login
 * (new setup and already-configured users). user_count is incremented after
 * a user's first successful 2FA setup and is never decremented when a 2FA
 * configuration is reset or deleted.
 */
final class AuthenticatorEnrollmentLimiter
{
    private const MYSQL_LOCK_NAME = 'miniorange_mfa_authenticator_enroll';
    private const POSTGRES_LOCK_KEY = 872016001;

    /**
     * Method values stored by frontend and backend enrollment flows, normalized.
     *
     * @var string[]
     */
    private const AUTHENTICATOR_METHODS = [
        'google',
        'microsoft',
        'google authenticator',
        'microsoft authenticator',
    ];

    public static function isAuthenticatorMethod(string $method): bool
    {
        return in_array(strtolower(trim($method)), self::AUTHENTICATOR_METHODS, true);
    }

    /**
     * Returns true when the free-plan 10-user cap is full.
     * At this point 2FA must be skipped for every user (new and existing).
     */
    public function isTwoFactorLimitReached(Connection $connection): bool
    {
        return $this->getTwoFactorSetupCount($connection) >= Constants::TWO_FACTOR_USER_LIMIT;
    }

    /**
     * Returns true when this identity has no 2FA configuration yet and the
     * free-plan 10-user setup cap is full.
     */
    public function isNewTwoFactorSetupBlocked(
        Connection $connection,
        string $username,
        int $isAdmin,
        ?string $websiteId
    ): bool {
        return !$this->canEnroll($connection, $username, $isAdmin, $websiteId);
    }

    /**
     * Advisory check for setup screens. Persist-time guardAndPersist is authoritative.
     */
    public function canEnroll(
        Connection $connection,
        string $username,
        int $isAdmin,
        ?string $websiteId
    ): bool {
        if ($this->findExistingMethod($connection, $username, $isAdmin, $websiteId) !== null) {
            return true;
        }

        return $this->getTwoFactorSetupCount($connection) < Constants::TWO_FACTOR_USER_LIMIT;
    }

    /**
     * Serializes first-time 2FA setup (lock → count → persist → increment)
     * so concurrent requests cannot both observe 9 users and create an 11th.
     *
     * Identities that already have any 2FA method persist without incrementing.
     *
     * @param callable():void $persist
     *
     * @throws AuthenticatorUserLimitReachedException
     */
    public function guardAndPersist(
        Connection $connection,
        string $username,
        int $isAdmin,
        ?string $websiteId,
        string $method,
        callable $persist
    ): void {
        if (trim($method) === '') {
            $persist();
            return;
        }

        $lockKind = $this->acquireEnrollmentLock($connection);
        $completed = false;
        try {
            $existingMethod = $this->findExistingMethod($connection, $username, $isAdmin, $websiteId);
            $setupCount = $this->getTwoFactorSetupCount($connection);
            if ($existingMethod === null && $setupCount >= Constants::TWO_FACTOR_USER_LIMIT) {
                throw new AuthenticatorUserLimitReachedException();
            }
            $persist();
            if ($existingMethod === null) {
                $this->incrementTwoFactorSetupCount($connection, $setupCount);
            }
            $completed = true;
        } finally {
            $this->releaseEnrollmentLock($connection, $lockKind, $completed);
        }
    }

    private function getTwoFactorSetupCount(Connection $connection): int
    {
        $value = $connection->select(
            [Constants::CUSTOMER_USER_COUNT],
            Constants::TABLE_CUSTOMER,
            ['id' => 1]
        )->fetchOne();

        if (is_int($value) && $value >= 0) {
            return $value;
        }
        if (!is_string($value) || $value === '') {
            return 0;
        }

        $decrypted = AESEncryption::decrypt_data($value);
        if (is_numeric($decrypted)) {
            return max(0, (int)$decrypted);
        }
        if (is_numeric($value)) {
            return max(0, (int)$value);
        }

        return Constants::TWO_FACTOR_USER_LIMIT;
    }

    private function incrementTwoFactorSetupCount(Connection $connection, int $currentCount): void
    {
        $encryptedCount = AESEncryption::encrypt_data((string)($currentCount + 1));
        $updated = $connection->update(
            Constants::TABLE_CUSTOMER,
            [Constants::CUSTOMER_USER_COUNT => $encryptedCount],
            ['id' => 1]
        );
        if ($updated > 0) {
            return;
        }

        $connection->insert(
            Constants::TABLE_CUSTOMER,
            [
                'id' => 1,
                Constants::CUSTOMER_USER_COUNT => $encryptedCount,
            ]
        );
    }

    /**
     * Prefer an existing authenticator method when duplicate rows exist for the
     * same identity, so a leftover EMAIL row cannot consume a second slot.
     */
    private function findExistingMethod(
        Connection $connection,
        string $username,
        int $isAdmin,
        ?string $websiteId
    ): ?string {
        $table = $connection->quoteIdentifier(Constants::TABLE_TFA_USERS);
        $sql = 'SELECT active_method FROM ' . $table
            . ' WHERE username = :username AND is_admin = :is_admin';
        $params = [
            'username' => $username,
            'is_admin' => $isAdmin,
        ];

        if ($isAdmin === 0) {
            $sql .= ' AND COALESCE(website_id, \'\') = :website_id';
            $params['website_id'] = (string)($websiteId ?? '');
        }

        $result = $connection->executeQuery($sql, $params);
        $fallback = null;
        while ($row = $result->fetchAssociative()) {
            if (!isset($row['active_method']) || !is_string($row['active_method'])) {
                continue;
            }
            $method = $row['active_method'];
            if ($method === '') {
                continue;
            }
            if (self::isAuthenticatorMethod($method)) {
                return $method;
            }
            if ($fallback === null) {
                $fallback = $method;
            }
        }

        return $fallback;
    }

    /**
     * @return 'mysql'|'pgsql'|'sqlite'
     */
    private function acquireEnrollmentLock(Connection $connection): string
    {
        $driver = $this->getDriverName($connection);

        if ($driver === 'pgsql') {
            $connection->executeQuery('SELECT pg_advisory_lock(' . self::POSTGRES_LOCK_KEY . ')');
            return 'pgsql';
        }

        if ($driver === 'sqlite') {
            if (!$connection->isTransactionActive()) {
                $connection->beginTransaction();
            }
            return 'sqlite';
        }

        $locked = $connection->executeQuery(
            'SELECT GET_LOCK(:lock_name, 10)',
            ['lock_name' => self::MYSQL_LOCK_NAME]
        )->fetchOne();
        if ($locked !== 1 && $locked !== '1') {
            throw new \RuntimeException('Unable to acquire authenticator enrollment lock.');
        }

        return 'mysql';
    }

    /**
     * @param 'mysql'|'pgsql'|'sqlite' $lockKind
     */
    private function releaseEnrollmentLock(Connection $connection, string $lockKind, bool $success): void
    {
        if ($lockKind === 'sqlite') {
            if (!$connection->isTransactionActive()) {
                return;
            }
            if ($success) {
                $connection->commit();
            } else {
                $connection->rollBack();
            }
            return;
        }

        if ($lockKind === 'pgsql') {
            $connection->executeQuery('SELECT pg_advisory_unlock(' . self::POSTGRES_LOCK_KEY . ')');
            return;
        }

        $connection->executeQuery(
            'SELECT RELEASE_LOCK(:lock_name)',
            ['lock_name' => self::MYSQL_LOCK_NAME]
        );
    }

    private function getDriverName(Connection $connection): string
    {
        $params = $connection->getParams();
        $driver = strtolower($params['driver'] ?? '');

        if (strpos($driver, 'pgsql') !== false || strpos($driver, 'postgres') !== false) {
            return 'pgsql';
        }
        if (strpos($driver, 'sqlite') !== false) {
            return 'sqlite';
        }

        $platformClass = get_class($connection->getDatabasePlatform());
        if (stripos($platformClass, 'PostgreSQL') !== false) {
            return 'pgsql';
        }
        if (stripos($platformClass, 'SQLite') !== false) {
            return 'sqlite';
        }

        return 'mysql';
    }
}
