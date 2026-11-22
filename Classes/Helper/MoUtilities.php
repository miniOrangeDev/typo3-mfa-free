<?php

declare(strict_types=1);

namespace Miniorange\Otp\Helper;

use Exception;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Messaging\FlashMessage;
use TYPO3\CMS\Core\Messaging\FlashMessageService;
use TYPO3\CMS\Core\Messaging\Renderer\ListRenderer;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\PathUtility;
use TYPO3\CMS\Core\Information\Typo3Version;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use Doctrine\DBAL\Result;
use Miniorange\Otp\Domain\Repository\Site\SiteRepository;
use Miniorange\Otp\Exception\AuthenticatorUserLimitReachedException;
use Miniorange\Otp\Services\AuthenticatorEnrollmentLimiter;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Exception\SiteNotFoundException;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Frontend\Authentication\FrontendUserAuthentication;
use CurlHandle;

const SEP = DIRECTORY_SEPARATOR;

class MoUtilities
{
    private static function executeSelectQuery(QueryBuilder $queryBuilder): Result
    {
        return $queryBuilder->executeQuery();
    }

    private static function executeModificationQuery(QueryBuilder $queryBuilder): int
    {
        return $queryBuilder->executeStatement();
    }

    /**
     * @return array<string, mixed>|false
     */
    private static function fetchAssociativeRow(QueryBuilder $queryBuilder): array|false
    {
        $result = self::executeSelectQuery($queryBuilder)->fetchAssociative();

        return is_array($result) ? $result : false;
    }

    private static function fetchOneValue(QueryBuilder $queryBuilder): mixed
    {
        return self::executeSelectQuery($queryBuilder)->fetchOne();
    }

    private static function tableExists(Connection $connection, string $table): bool
    {
        return $connection->createSchemaManager()->tablesExist([$table]);
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function extractStringColumn(array $row, string $column): string
    {
        if (!isset($row[$column])) {
            return '';
        }

        $value = $row[$column];

        return is_string($value) || is_numeric($value) ? (string)$value : '';
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function extractNullableStringColumn(array $row, string $column): ?string
    {
        if (!isset($row[$column])) {
            return null;
        }

        $value = $row[$column];

        return is_string($value) || is_numeric($value) ? (string)$value : null;
    }

    public static function getHelperDir(): string
    {
        global $sep;
        $relPath = self::getExtensionRelativePath();
        $sep = substr($relPath, -1);
        $helperFolder = $relPath . 'Helper' . $sep;
        error_log("Relative Resource folder : " . print_r($helperFolder, true));
        return $helperFolder;
    }

    public static function getExtensionRelativePath(): string
    {
        return PathUtility::getAbsoluteWebPath(self::getExtensionAbsolutePath());
    }

    public static function getExtensionAbsolutePath(): string
    {
        return ExtensionManagementUtility::extPath('mfa');
    }

    /**
     * @return array<string, mixed>|false
     */
    public static function fetchUserFromUsername(string $username, int|string|null $pid = null): array|false
    {
        $pid = $pid ?? 0;
        $table = Constants::TABLE_FE_USERS;
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable($table);
        // Remove all restrictions but add DeletedRestriction again
        $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));
        $var_uid = self::fetchAssociativeRow(
            $queryBuilder->select('*')
                ->from($table)
                ->where(
                    $queryBuilder->expr()->eq('username', $queryBuilder->createNamedParameter($username, Connection::PARAM_STR)),
                    $queryBuilder->expr()->eq('pid', $queryBuilder->createNamedParameter($pid, Connection::PARAM_INT))
                )
        );
        if ($var_uid === false) {
            return false;
        }
        return $var_uid;
    }

    /**
     * Generate a random TOTP secret (base32, for Google/Microsoft Authenticator).
     */
    public static function generateTotpSecret(int $length = 16): string
    {
        $base32Chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $secret = '';
        for ($i = 0; $i < $length; $i++) {
            $secret .= $base32Chars[random_int(0, 31)];
        }
        return $secret;
    }

    /**
     * Get the authenticator secret for a backend user from miniorange_tfa_users.
     * Returns null if user has no stored secret.
     */
    public static function getAuthenticatorSecretForBackendUser(string $username): ?string
    {
        $table = Constants::TABLE_TFA_USERS;
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable($table);
        $query = $queryBuilder->select('secret')
            ->from($table)
            ->where($queryBuilder->expr()->eq('username', $queryBuilder->createNamedParameter($username, Connection::PARAM_STR)),
                $queryBuilder->expr()->eq('is_admin', $queryBuilder->createNamedParameter(1, Connection::PARAM_INT))
            );

        $result = self::fetchAssociativeRow($query);

        if (is_array($result) && !empty($result['secret'])) {
            return self::extractStringColumn($result, 'secret');
        }
        return null;
    }

    /**
     * --------- UPDATE CUSTOMER DETAILS --------------------------------
     */
    public static function update_cust(string $column, string|int|null $value): void
    {
        if (self::fetch_cust('id') == null) {
            self::insertValue();
        }
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('miniorange_tfa_customer');
        self::executeModificationQuery(
            $queryBuilder->update('miniorange_tfa_customer')
                ->where($queryBuilder->expr()->eq('id', $queryBuilder->createNamedParameter(1, Connection::PARAM_INT)))
                ->set($column, $value)
        );
    }

    /**
     *---------FETCH CUSTOMER DETAILS-------------------------
     */
    public static function fetch_cust(string $col): ?string
    {
        try {
            $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
                ->getQueryBuilderForTable('miniorange_tfa_customer');

            $result = $queryBuilder
                ->select($col)
                ->from('miniorange_tfa_customer')
                ->where(
                    $queryBuilder->expr()->eq('id', $queryBuilder->createNamedParameter(1, Connection::PARAM_INT))
                );

            $row = self::fetchAssociativeRow($result);

            return is_array($row) ? self::extractNullableStringColumn($row, $col) : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     *---------INSERT CUSTOMER DETAILS--------------
     */
    public static function insertValue(): void
    {
        try {
            $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('miniorange_tfa_customer');
            self::executeModificationQuery(
                $queryBuilder->insert('miniorange_tfa_customer')->values(['id' => '1'])
            );
        } catch (\Throwable $e) {
            // Table may not exist yet – skip insert
        }
    }

    /**
     * Get Image Resource URL
     */
    public static function getImageUrl(string $imgFileName): string
    {
        error_log("getImageUrl");
        $iconDir = self::getExtensionRelativePath() . '/Resources/Public/Icons/';
        error_log("iconDir : " . print_r($iconDir, true));
        return $iconDir . $imgFileName;
    }

    /**
     * The function returns the current page URL.
     * @return string
     */
    public static function currentPageUrl(): string
    {
        $requestUri = isset($_SERVER['REQUEST_URI']) && is_string($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';

        return self::getBaseUrl() . $requestUri;
    }

    /**
     * Get the base url of the site.
     * @return string
     */
    public static function getBaseUrl(): string
    {
        $pageURL = 'http';

        if ((isset($_SERVER['HTTPS'])) && ($_SERVER['HTTPS'] == 'on')) {
            $pageURL .= 's';
        }

        $pageURL .= '://';

        $serverPort = TypeAssert::stringValue($_SERVER['SERVER_PORT'] ?? null, '80');
        $serverName = isset($_SERVER['SERVER_NAME']) && is_string($_SERVER['SERVER_NAME']) ? $_SERVER['SERVER_NAME'] : '';

        if ($serverPort !== '80') {
            $pageURL .= $serverName . ':' . $serverPort;
        } else {
            $pageURL .= $serverName;
        }

        return $pageURL;
    }

    //Check if a value is null or empty
    public static function isEmptyOrNull(mixed $t): bool
    {
        if (!isset($t) || empty($t)) {
            return true;
        }
        return false;
    }

    //------------Fetch UID from Groups
    public static function fetchUidFromGroupName(string $name, string $table = 'fe_groups'): int|string|false
    {
        if ($table === 'fe_groups') {
            $name = str_replace('fe', '', $name);
        }
        if ($table === 'be_groups') {
            $name = str_replace('be', '', $name);
        }
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable($table);
        $rows = self::fetchAssociativeRow(
            $queryBuilder->select('uid')
                ->from($table)
                ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($name, Connection::PARAM_STR)))
        );

        if (!is_array($rows)) {
            return false;
        }

        $uid = $rows['uid'] ?? false;

        return is_int($uid) || is_string($uid) ? $uid : false;
    }

    public static function fetchDefaultGroupUidFromGroupName(string $name, string $table = 'fe_groups'): int|string|false
    {
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable($table);
        $rows = self::fetchAssociativeRow(
            $queryBuilder->select('uid')
                ->from($table)
                ->where($queryBuilder->expr()->eq('title', $queryBuilder->createNamedParameter($name, Connection::PARAM_STR)))
        );

        if (!is_array($rows)) {
            return false;
        }

        $uid = $rows['uid'] ?? false;

        return is_int($uid) || is_string($uid) ? $uid : false;
    }


    // -------------UPDATE TABLE---------------------------------------
    public static function updateTable(string $col, string|int|null $val, string $table, string $app_name): void
    {
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable($table);
        self::executeModificationQuery(
            $queryBuilder->update($table)
                ->where($queryBuilder->expr()->eq('app_name', $queryBuilder->createNamedParameter($app_name, Connection::PARAM_STR)))
                ->set($col, $val)
        );
    }

    //Fetch a value from Database
    public static function fetchFromDb(string $col, string $table, string $app_name): string
    {
        $connection = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable($table);

        if (!self::tableExists($connection, $table)) {
            return '';
        }

        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable($table);
        $columnValue = self::fetchAssociativeRow(
            $queryBuilder->select($col)
                ->from($table)
                ->where($queryBuilder->expr()->eq('app_name', $queryBuilder->createNamedParameter($app_name, Connection::PARAM_STR)))
        );

        if (!is_array($columnValue)) {
            return '';
        }

        return self::extractStringColumn($columnValue, $col);
    }

    public static function clearFlashMessages(): void
    {
        $flashMessageService = GeneralUtility::makeInstance(FlashMessageService::class);
        $messageQueue = $flashMessageService->getMessageQueueByIdentifier();
        $messageQueue->clear();
    }

    public static function generateRandomAlphanumericValue(int $length): string
    {
        $chars = 'abcdef0123456789';
        $uniqueID = '';
        for ($i = 0; $i < $length; $i++) {
            $uniqueID .= substr($chars, rand(0, 15), 1);
        }
        return 'a' . $uniqueID;
    }

    public static function log_php_error(string $msg = '', mixed $obj = null): void
    {
        error_log($msg . ': ' . print_r($obj, true) . "\n\n");
    }

    /**
     * This function checks if a value is set or
     * empty. Returns true if value is empty
     */
    public static function isBlank(mixed $value): bool
    {
        if (!isset($value) || empty($value)) {
            return true;
        }
        return false;
    }

    //---------FETCH CUSTOMER DETAILS-------------------------

    /**
     * @param array<string, mixed> $content
     */
    public static function saveCustomer(array $content, string $email): void
    {
        self::updateCustomer(Constants::CUSTOMER_KEY, is_scalar($content['id'] ?? null) ? (string)$content['id'] : '');
        self::updateCustomer(Constants::CUSTOMER_API_KEY, is_scalar($content['apiKey'] ?? null) ? (string)$content['apiKey'] : '');
        self::updateCustomer(Constants::CUSTOMER_TOKEN, is_scalar($content['token'] ?? null) ? (string)$content['token'] : '');
        self::updateCustomer(Constants::CUSTOMER_REGSTATUS, 'logged');
        self::updateCustomer(Constants::CUSTOMER_EMAIL, $email);
    }

    public static function updateCustomer(string $column, string|int|null $value): void
    {
        if (self::fetchFromCustomer('id') == null) {
            self::insertCustomerRow();
        }
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable(Constants::TABLE_CUSTOMER);
        $affectedRows = $queryBuilder->update(Constants::TABLE_CUSTOMER)->where($queryBuilder->expr()->eq('id', $queryBuilder->createNamedParameter(1, Connection::PARAM_INT)))->set($column, $value);
        MoUtilities::executeAccordingToTypo3Version($affectedRows);
    }

    public static function fetchFromCustomer(string $col): ?string
    {
        try {
            $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable(Constants::TABLE_CUSTOMER);
            $variable = self::fetchAssociativeRow(
                $queryBuilder->select($col)
                    ->from(Constants::TABLE_CUSTOMER)
                    ->where($queryBuilder->expr()->eq('id', $queryBuilder->createNamedParameter(1, Connection::PARAM_INT)))
            );

            return is_array($variable) ? self::extractNullableStringColumn($variable, $col) : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * The miniOrange account is logged in when the stored registration status is logged or verified.
     */
    public static function isCustomerLoggedIn(): bool
    {
        $regStatus = self::fetchFromCustomer(Constants::CUSTOMER_REGSTATUS);

        return $regStatus === 'logged' || $regStatus === 'verified';
    }

    //  SAVE CUSTOMER

    public static function insertCustomerRow(): void
    {
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable(Constants::TABLE_CUSTOMER);
        self::executeModificationQuery(
            $queryBuilder->insert(Constants::TABLE_CUSTOMER)->values(['id' => '1'])
        );
    }

    // FETCH CUSTOMER

    public static function showErrorFlashMessage(string $message, string $header = 'ERROR'): void
    {
        $flashMessage = GeneralUtility::makeInstance(
            FlashMessage::class,
            $message,
            $header,
            ContextualFeedbackSeverity::ERROR
        );
        $out = GeneralUtility::makeInstance(ListRenderer::class)->render([$flashMessage]);
        echo $out;
    }

    // ---- UPDATE CUSTOMER Details

    public static function showSuccessFlashMessage(string $message, string $header = 'Success'): void
    {
        $flashMessage = GeneralUtility::makeInstance(
            FlashMessage::class,
            $message,
            $header,
            ContextualFeedbackSeverity::OK
        );
        $out = GeneralUtility::makeInstance(ListRenderer::class)->render([$flashMessage]);
        echo $out;
    }

    public static function addProxyDetailsToCurlCall(\CurlHandle $ch): \CurlHandle
    {
        $proxy_needed = self::fetch_cust('proxy_needed');
        if (TypeAssert::intValue($proxy_needed) === 1) {
            $proxy_settings = self::fetch_cust('proxy_settings');
            $proxyJson = is_string($proxy_settings) ? $proxy_settings : '';
            $proxy = json_decode($proxyJson, true);
            if (!is_array($proxy)) {
                $proxy = [];
            }
            $proxy_host_name = isset($proxy['proxy_host_name']) && is_string($proxy['proxy_host_name']) ? $proxy['proxy_host_name'] : '';
            $port_number = TypeAssert::intValue($proxy['port_number'] ?? null);
            $username = isset($proxy['username']) && is_string($proxy['username']) ? $proxy['username'] : '';
            $password = isset($proxy['password']) && is_string($proxy['password']) ? TypeAssert::stringValue(base64_decode($proxy['password'])) : '';

            if ($proxy_host_name !== '') {
                curl_setopt($ch, CURLOPT_PROXY, $proxy_host_name);
                curl_setopt($ch, CURLOPT_PROXYPORT, $port_number);
                curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
                curl_setopt($ch, CURLOPT_PROXYUSERPWD, $username . ':' . $password);
            }
        }
        return $ch;
    }

    /**
     * Function to flush cahe of a specific page
     */
    public static function flushPageCache(string $url): void
    {
        $pageId = 0;
        // Parse the URL
        $parsedUrl = parse_url($url);
        $path = is_array($parsedUrl) ? TypeAssert::stringValue($parsedUrl['path'] ?? null) : '';
        if ($path !== '') {
            $path = trim($path, '/');
            $connection = GeneralUtility::makeInstance(ConnectionPool::class)
                ->getConnectionForTable('pages');
            $queryBuilder = $connection->createQueryBuilder();
            $result = self::fetchAssociativeRow(
                $queryBuilder->select('uid')
                    ->from('pages')
                    ->where($queryBuilder->expr()->like('slug', $queryBuilder->createNamedParameter($path, Connection::PARAM_STR)))
            );
            if (is_array($result)) {
                $pageId = TypeAssert::intValue($result['uid'] ?? null);
            }
        }

        $cacheManager = GeneralUtility::makeInstance(CacheManager::class);
        $cacheManager->flushCachesByTag('pageId_' . $pageId);
    }

    /**
     * Function to fetch version of current Typo3 instance
     */
    public static function getTypo3Version(): string
    {
        $version = new Typo3Version();
        return $version->getVersion();
    }

    /**
     * Return the extension version from composer.json via TYPO3 PackageManager.
     */
    public static function getExtensionVersion(): string
    {
        return (string)ExtensionManagementUtility::getExtensionVersion('mfa');
    }

    /**
     * function to execute queries based on Typo3 version
     */
    public static function executeAccordingToTypo3Version(QueryBuilder $affectedRows): void
    {
        self::executeModificationQuery($affectedRows);
    }

    /**
     * function to get miniOrange api
     *
     * @return array<string, string>
     */
    public static function getApiUrls(): array
    {
        $hostName = 'https://login.xecurify.com';
        return [
            'challange' => $hostName . '/moas/api/auth/challenge',
            'update' => $hostName . '/moas/api/admin/users/update',
            'validate' => $hostName . '/moas/api/auth/validate',
            'googleAuthService' => $hostName . '/moas/api/auth/google-auth-secret',
            'googlevalidate' => $hostName . '/moas/api/auth/validate-google-auth-secret',
            'createUser' => $hostName . '/moas/api/admin/users/create',
            'kbaRegister' => $hostName . '/moas/api/auth/register',
            'getUserInfo' => $hostName . '/moas/api/admin/users/get',
            'feedback' => $hostName . '/moas/api/notify/send',
        ];
    }

     /**
      * Get registered customer details from DB
      *
      * @return array{email: ?string, customer_Key: ?string, api_Key: ?string, token: ?string}|null
      */
     /**
      * @return array<string, mixed>|null
      */
     public static function getCustomerDetails(): ?array
     {
         // Fetch customer table and check if it has a record; if yes, get the details
         $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
             ->getQueryBuilderForTable(Constants::TABLE_CUSTOMER);

         $result = self::fetchAssociativeRow(
             $queryBuilder->select('*')
                 ->from(Constants::TABLE_CUSTOMER)
                 ->where($queryBuilder->expr()->eq('id', $queryBuilder->createNamedParameter(1, Connection::PARAM_INT)))
         );

         // Check if a record exists in the customer table
         if ($result === false) {
             return null;
         }

         $details = [
             'email' => self::extractNullableStringColumn($result, 'cust_email'),
             'customer_Key' => self::extractNullableStringColumn($result, 'cust_key'),
             'api_Key' => self::extractNullableStringColumn($result, 'cust_api_key'),
             'token' => self::extractNullableStringColumn($result, 'cust_token'),
         ];

         return $details;
     }

    /**
     * function to send the otp to the sms
     *
     */
    public static function sendOtpSms(string $email, string $method, string $phone = ''): string|false
    {
        // Your SMS sending logic here
        // Example: call SMS gateway API
        
        $authCodes = array('OOE' => 'EMAIL', 'OOS' => 'SMS', 'OOSE' => 'SMS AND EMAIL', 'KBA' => 'KBA', 'OOW' => 'SMS');

        $authType = 'OOS'; // Default
        if (strtolower($method) === 'email') {
            $authType = 'OOE';
        } elseif (strtolower($method) === 'sms_email') {
            $authType = 'OOSE';
        }

        $customerKeys = self::getCustomerKeys(false);

        $customerKey = $customerKeys['customer_key'];
        $apiKey = $customerKeys['apiKey'];

        $fields = [
            'customerKey' => $customerKey,
            'username' => '',
            'phone' => $phone,
            'email' => $email,
            'authType' => $authCodes[$authType],
            'transactionName' => 'Typo 3 2FA Plugin',
        ];

        $urls = self::getApiUrls();

        $url = $urls['challange'];
        return self::challenge($customerKey, $apiKey, $url, $fields);
    }

    /**
     * function to challenge the api
     *
     * @param array<string, mixed> $fields
     */
    public static function challenge(string $customerKey, string $apiKey, string $url, array $fields): string|false
    {

        $authHeader = self::createAuthHeader($customerKey, $apiKey);
        $response = self::callAPI($url, $fields, $authHeader);

        return $response;
    }

    /**
     * function to call the api
     *
     * @param array<string, mixed> $jsonData
     * @param array<int, string> $headers
     */
    private static function callAPI(string $url, array $jsonData = [], array $headers = ['Content-Type: application/json']): string|false
    {
        $ch = curl_init($url);
        if ($ch === false) {
            return false;
        }

        // Determine if we should send JSON or form data
        $isJson = in_array('Content-Type: application/json', $headers, true);
        $data = $isJson ? json_encode($jsonData) : http_build_query($jsonData);
        if (!is_string($data)) {
            $data = '';
        }

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 10);

        if (!empty($jsonData)) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        }

        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $response = curl_exec($ch);

        if (curl_errno($ch)) {
            error_log('cURL error: ' . curl_error($ch));
        }

        curl_close($ch);
        return is_string($response) ? $response : false;
    }

    /**
     * function to create the auth header
     *
     * @return array<int, string>
     */
    private static function createAuthHeader(string $customerKey, string $apiKey): array
    {
        $currentTimestampInMillis = round(microtime(true) * 1000);
        $currentTimestampInMillis = number_format($currentTimestampInMillis, 0, '', '');

        $stringToHash = $customerKey . $currentTimestampInMillis . $apiKey;
        $authHeader = hash("sha512", $stringToHash);

        $header = [
            'Content-Type: application/json',
            "Customer-Key: $customerKey",
            "Timestamp: $currentTimestampInMillis",
            "Authorization: $authHeader",
        ];
        return $header;
    }

    /**
     * function to get the customer keys
     *
     * @return array{customer_key: string, apiKey: string}
     */
    public static function getCustomerKeys(bool $isMiniorange = false): array
    {
        $keys = [];
        if ($isMiniorange) {

            $keys['customer_key'] = '';
            $keys['apiKey'] = '';
        } else {
            $details = self::getCustomerDetails();
            $keys['customer_key'] = is_array($details) ? TypeAssert::stringValue($details['customer_Key'] ?? null) : '';
            $keys['apiKey'] = is_array($details) ? TypeAssert::stringValue($details['api_Key'] ?? null) : '';
        }
        return $keys;
    }

    /**
     * function to send the otp via miniorange
     *
     */
    public static function sendOtpViaMiniorange(string $email, string $method, string $fullPhone = ''): string|false
    {
        $authType = 'OOE';

        if ($method == 'sms') {
            $authType = 'OOS';
        } elseif ($method == 'email') {
            $authType = 'OOE';
        } elseif ($method == 'sms_email') {
            $authType = 'OOSE';
        } elseif ($method == 'google' || $method == 'microsoft' || $method == 'authy' || $method == 'duo') {
            $authType = 'GOOGLE AUTHENTICATOR';
        }

        $authCodes = ['OOE' => 'EMAIL', 'OOS' => 'SMS', 'OOSE' => 'SMS AND EMAIL', 'KBA' => 'KBA', 'OOW' => 'SMS'];
        
        $customerKeys = self::getCustomerKeys(false);

        $customerKey = $customerKeys['customer_key'];
        $apiKey = $customerKeys['apiKey'];
         
        if ($method == 'google') {
            $fields = [
                'customerKey' => $customerKey,
                'username' => $email,
                'googleAuthenticatorName' => $authType,
                'authenticatorName' => 'test',
            ];
            $urls = self::getApiUrls();
    
            $url = $urls['googleAuthService'];
        } else {
            $fields = [
                'customerKey' => $customerKey,
                'username' => '',
                'phone' => $fullPhone,
                'email' => $email,
                'authType' => $authCodes[$authType] ?? $authType,
                'transactionName' => 'Typo 3 2FA Plugin',
            ];
            $urls = self::getApiUrls();
            $url = $urls['challange'];
        }

        return self::challenge($customerKey, $apiKey, $url, $fields);
    }

    /**
     * function to validate the otp via miniorange
     *
     * @param array<string, mixed> $fields
     */
    public static function validate(string $customerKey, string $apiKey, string $url, array $fields): string|false
    {

        $authHeader = self::createAuthHeader($customerKey, $apiKey);
        $response = self::callAPI($url, $fields, $authHeader);
        return $response;
    }


    /**
     * function to get all the mo tfa user details
     *
     * @return array<string, mixed>|null
     */
    public static function getAllMoTfaUserDetails(string $table, string|false $username = false, string|false $website_id = false): ?array
    {
        // Build query for miniorange_tfa_users table using username and website_id
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable($table);

        $expr = $queryBuilder->expr();
        $conditions = [];

        if ($username !== false && $username !== '') {
            $conditions[] = $expr->eq('username', $queryBuilder->createNamedParameter($username));
        }
        if ($website_id !== false && $website_id !== '') {
            $conditions[] = $expr->eq('website_id', $queryBuilder->createNamedParameter($website_id));
        }

        if (empty($conditions)) {
            // If no username or website_id provided, return null
            return null;
        }

        $result = self::fetchAssociativeRow(
            $queryBuilder->select('*')
                ->from($table)
                ->where(...$conditions)
                ->setMaxResults(1)
        );

        if ($result === false) {
            return null;
        }

        return $result;
    }

    /**
     * function to get the website id
     *
     */
    public static function getWebsiteId(): string
    {
        $request = TypeAssert::serverRequest($GLOBALS['TYPO3_REQUEST'] ?? null);
        if ($request instanceof ServerRequestInterface) {
            $site = $request->getAttribute('site');
            if ($site instanceof Site) {
                return $site->getIdentifier();
            }
        }

        $tsfe = $GLOBALS['TSFE'] ?? null;
        if ($tsfe instanceof \TYPO3\CMS\Frontend\Controller\TypoScriptFrontendController) {
            $currentPageId = TypeAssert::intValue($tsfe->id, 0);
            if ($currentPageId > 0) {
                try {
                    $siteFinder = GeneralUtility::makeInstance(SiteFinder::class);

                    return $siteFinder->getSiteByPageId($currentPageId)->getIdentifier();
                } catch (SiteNotFoundException) {
                    // Backend or unmapped page: fall through to the main website.
                }
            }
        }

        $mainWebsiteId = GeneralUtility::makeInstance(SiteRepository::class)->findMainIdentifier();

        return $mainWebsiteId ?? '';
    }

    /**
     * function to verify the otp via miniorange
     *
     */
    public static function verifyOtpViaMiniorange(string $email, string $enteredOtp, string $method, string $transactionID, string $fullPhone = ''): string|false
    {
        $authCodes = ['OOE' => 'EMAIL', 'OOS' => 'SMS', 'OOW' => 'SMS', 'OOSE' => 'SMS AND EMAIL'];

        if ($method == 'sms') {
            $authType = 'OOS';
        } elseif ($method == 'email') {
            $authType = 'OOE';
        } elseif ($method == 'sms_email') {
            $authType = 'OOSE';
        }

        $customerKeys = self::getCustomerKeys(false);
        $customerKey = $customerKeys['customer_key'];
        $apiKey = $customerKeys['apiKey'];

        $fields = [
            'customerKey' => $customerKey,
            'txId' => $transactionID,
            'token' => str_replace(' ', '', $enteredOtp),
        ];

        $urls = self::getApiUrls();
        $url = $urls['validate'];
        return self::validate($customerKey, $apiKey, $url, $fields);
    }

    /**
     * function to get the otp length
     *
     */
    public static function getOtpLength(): int
    {
        return self::getOtpLengthFromSettings();
    }

    /**
     * function to get the otp length from settings
     *
     */
    public static function getOtpLengthFromSettings(): int
    {
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('miniorange_tfa_settings');
        $result = self::fetchAssociativeRow(
            $queryBuilder->select('otp_length')
                ->from('miniorange_tfa_settings')
                ->where($queryBuilder->expr()->eq('customer_2fa', $queryBuilder->createNamedParameter(1, Connection::PARAM_INT)))
                ->setMaxResults(1)
        );

        // Return the OTP length if found, otherwise default to 6
        if ($result === false) {
            return 6;
        }

        $otpLength = $result['otp_length'] ?? null;

        return is_numeric($otpLength) ? (int)$otpLength : 6;
    }

    /**
     * function to verify the gauth code
     *
     */
    public static function verifyGauthCode(string $code, string $current_username, string $secret, int $discrepancy = 3, ?int $currentTimeSlice = null): string
    {
       
        $response = ['status' => 'FALSE'];
        if ($currentTimeSlice === null) {
            $currentTimeSlice = (int)floor(time() / 30);
        }

        if (strlen($code) != 6) {
            return json_encode($response) ?: '';
        }

        for ($i = -$discrepancy; $i <= $discrepancy; ++$i) {
            $calculatedCode = self::getCode($secret, $currentTimeSlice + $i);
            if (self::timingSafeEquals($calculatedCode, $code)) {
                $response['status'] = 'SUCCESS';
                return json_encode($response) ?: '';
            }
        }
        return json_encode($response) ?: '';
    }

    /**
     * function to get the code
     *
     */
    public static function getCode(string $secret, ?int $timeSlice = null): string
    {
        if ($timeSlice === null) {
            $timeSlice = (int)floor(time() / 30);
        }

        $secretkey = self::_base32Decode($secret);
        if (!is_string($secretkey) || $secretkey === '') {
            return '000000';
        }
        // Pack time into binary string
        $time = chr(0) . chr(0) . chr(0) . chr(0) . pack('N*', $timeSlice);
        // Hash it with users secret key
        $hm = hash_hmac('SHA1', $time, $secretkey, true);

        // Use last nipple of result as index/offset
        $offset = ord(substr($hm, -1)) & 0x0F;

        // grab 4 bytes of the result
        $hashpart = substr($hm, $offset, 4);
        // Unpak binary value
        $value = unpack('N', $hashpart);
        if (!is_array($value) || !isset($value[1])) {
            return '000000';
        }
        $rawValue = $value[1];
        if (!is_int($rawValue) && !(is_string($rawValue) && is_numeric($rawValue))) {
            return '000000';
        }
        $value = (int)$rawValue;
        $value = $value & 0x7FFFFFFF;
        $modulo = pow(10, 6);
        $otp = (string)($value % $modulo);
        return str_pad($otp, 6, '0', STR_PAD_LEFT);
    }

    /**
     * function to decode the base32
     *
     */
    public static function _base32Decode(string $secret): string|false
    {
        if (empty($secret)) {
            return '';
        }
        $base32chars = self::_getBase32LookupTable();
        $base32charsFlipped = array_flip($base32chars);

        $paddingCharCount = substr_count($secret, $base32chars[32]);
        $allowedValues = array(6, 4, 3, 1, 0);
        if (!in_array($paddingCharCount, $allowedValues)) {
            return false;
        }

        for ($i = 0; $i < 4; ++$i) {
            if ($paddingCharCount == $allowedValues[$i] &&
                substr($secret, -($allowedValues[$i])) != str_repeat($base32chars[32], $allowedValues[$i])) {
                return false;
            }
        }
        $secret = str_replace('=', '', $secret);
        $secret = str_split($secret);
        $binaryString = '';
        $secretLen = count($secret);

        for ($i = 0; $i < $secretLen; $i += 8) {
            $x = '';
            // Make sure we have 8 chars, pad with 'A' (which is 0 in base32) if not
            $block = array_slice($secret, $i, 8);
            $blockLen = count($block);
            if ($blockLen < 8) {
                $block = array_merge($block, array_fill(0, 8 - $blockLen, 'A'));
            }
            for ($j = 0; $j < 8; ++$j) {
                $char = $block[$j];
                if (!isset($base32charsFlipped[$char])) {
                    return false;
                }
                // base_convert expects string, so cast index to string
                $indexAsString = (string)$base32charsFlipped[$char];
                $x .= str_pad(base_convert($indexAsString, 10, 2), 5, '0', STR_PAD_LEFT);
            }
            $eightBits = str_split($x, 8);
            foreach ($eightBits as $bits) {
                if (strlen($bits) < 8) {
                    continue;
                }
                $codepoint = bindec($bits);
                $binaryString .= chr(is_int($codepoint) ? $codepoint : (int)$codepoint);
            }
        }

        return $binaryString;
    }

    /**
     * @return array<int, string>
     */
    public static function _getBase32LookupTable(): array
    {
        return [
            'A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', //  7
            'I', 'J', 'K', 'L', 'M', 'N', 'O', 'P', // 15
            'Q', 'R', 'S', 'T', 'U', 'V', 'W', 'X', // 23
            'Y', 'Z', '2', '3', '4', '5', '6', '7', // 31
            '=',  // padding char
        ];
    }

    /**
     * function to check if the timing safe equals
     *
     */
    public static function timingSafeEquals(string $safeString, string $userString): bool
    {
        if (function_exists('hash_equals')) {
            return hash_equals($safeString, $userString);
        }
        $safeLen = strlen($safeString);
        $userLen = strlen($userString);

        if ($userLen != $safeLen) {
            return false;
        }

        $result = 0;

        for ($i = 0; $i < $userLen; ++$i) {
            $result |= (ord($safeString[$i]) ^ ord($userString[$i]));
        }

        // They are only identical strings if $result is exactly 0...
        return $result === 0;
    }

    /**
     * Check if the API error message indicates transaction limit exceeded.
     */
    public static function isTransactionLimitExceededMessage(string $message): bool
    {
        if ($message === '') {
            return false;
        }
        $normalized = strtolower($message);
        return str_contains($normalized, 'transaction limit') && str_contains($normalized, 'exceeded');
    }

    /**
     * SMS and SMS+Email challenges use the SMS transaction balance.
     */
    public static function isSmsTwoFactorMethod(string $method): bool
    {
        $normalized = strtolower(trim(str_replace([' ', '-'], '_', $method)));

        return $normalized === 'sms' || $normalized === 'sms_email' || $normalized === 'sms_and_email';
    }

    public static function get_sms_email_detail(): object|false
    {
        $customer_key = self::fetchFromCustomer(Constants::CUSTOMER_KEY);
        $api_key = self::fetchFromCustomer(Constants::CUSTOMER_API_KEY);

        if (!is_string($customer_key) || !is_string($api_key) || $customer_key === '' || $api_key === '') {
            return false;
        }
        return self::get_email_sms_transactions($customer_key, $api_key);
    }

    /**
     * Fetch remaining email/SMS transactions for the miniOrange account.
     */
    public static function get_email_sms_transactions(string $customerKey, string $apiKey): object|false
	{
		$url = Constants::HOSTNAME . '/moas/rest/customer/license';
        $fields = [
            'customerId' => $customerKey,
            'applicationName' => Constants::APPLICATION_NAME,
        ];

        $authHeader = self::createAuthHeader($customerKey, $apiKey);
        $response = self::callAPI($url, $fields, $authHeader);
        if (!is_string($response)) {
            return false;
        }
        $result = json_decode($response);

        if (!is_object($result) || !isset($result->status) || $result->status != 'SUCCESS') {
            $fields = [
                'customerId' => $customerKey,
                'licenseType' => 'DEMO',
            ];
            $authHeader = self::createAuthHeader($customerKey, $apiKey);
            $response = self::callAPI($url, $fields, $authHeader);
            if (!is_string($response)) {
                return false;
            }
            $result = json_decode($response);
        }

        return is_object($result) ? $result : false;
	}

    /**
     * Get or generate TOTP secret for a backend TFA user (miniorange_tfa_users).
     */
    public static function getOrGenerateSecretForBackendUser(string $username): string
    {
        $existing = self::getAuthenticatorSecretForBackendUser($username);
        if ($existing !== null && $existing !== '') {
            return $existing;
        }
        return self::generateTotpSecret(16);
    }

    /**
     * Get phone number for a backend TFA user from miniorange_tfa_users.
     */
    public static function getUserPhoneFromDb(string $username): string
    {
        /** @var array<string, string> $cache */
        static $cache = [];
        if (isset($cache[$username])) {
            return $cache[$username];
        }

        $table = 'miniorange_tfa_users';
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable($table);
        $query = $queryBuilder->select('phone')->from($table)->where(
            $queryBuilder->expr()->eq('username', $queryBuilder->createNamedParameter($username, Connection::PARAM_STR)),
            $queryBuilder->expr()->eq('is_admin', $queryBuilder->createNamedParameter(1, Connection::PARAM_INT))
        );

        $userRow = self::fetchAssociativeRow($query);

        return $cache[$username] = is_array($userRow) ? self::extractStringColumn($userRow, 'phone') : '';
    }

    /**
     * Get email for a backend TFA user from miniorange_tfa_users (e.g. previously saved when using EMAIL 2FA).
     */
    public static function getUserEmailFromDb(string $username): string
    {
        /** @var array<string, string> $cache */
        static $cache = [];
        if (isset($cache[$username])) {
            return $cache[$username];
        }

        $table = 'miniorange_tfa_users';
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable($table);
        $query = $queryBuilder->select('email')->from($table)->where(
            $queryBuilder->expr()->eq('username', $queryBuilder->createNamedParameter($username, Connection::PARAM_STR)),
            $queryBuilder->expr()->eq('is_admin', $queryBuilder->createNamedParameter(1, Connection::PARAM_INT))
        );

        $userRow = self::fetchAssociativeRow($query);

        return $cache[$username] = is_array($userRow) ? trim(self::extractStringColumn($userRow, 'email')) : '';
    }

    /**
     * Persist a freshly entered OTP-over-email address back to be_users.email, so the
     * backend user is not asked for it again on the next login. Only called after the
     * OTP sent to that address has been successfully verified.
     */
    public static function updateBackendUserEmail(int $uid, string $email): void
    {
        if ($uid <= 0 || $email === '') {
            return;
        }

        GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('be_users')
            ->update('be_users', ['email' => $email], ['uid' => $uid]);
    }

    /**
     * Save or update backend TFA user in miniorange_tfa_users.
     */
    public static function saveUserToTfaTable(string $username, string $email, string $method, string $secret = '', string $phone = ''): void
    {
        $table = 'miniorange_tfa_users';
        $connection = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable($table);
        $limiter = GeneralUtility::makeInstance(AuthenticatorEnrollmentLimiter::class);
        $limiter->guardAndPersist(
            $connection,
            $username,
            1,
            null,
            $method,
            static function () use ($connection, $table, $username, $email, $method, $secret, $phone): void {
                if (AuthenticatorEnrollmentLimiter::isAuthenticatorMethod($method)) {
                    self::persistBackendTfaUserOnConnection(
                        $connection,
                        $table,
                        $username,
                        $email,
                        $method,
                        $secret,
                        $phone
                    );
                    return;
                }

                $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable($table);

                $exists = self::fetchOneValue(
                    $queryBuilder->select('id')->from($table)->where(
                        $queryBuilder->expr()->eq('username', $queryBuilder->createNamedParameter($username, Connection::PARAM_STR)),
                        $queryBuilder->expr()->eq('is_admin', $queryBuilder->createNamedParameter(1, Connection::PARAM_INT))
                    )
                );

                $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable($table);

                if ($exists) {
                    $qb = $queryBuilder->update($table)->where(
                        $queryBuilder->expr()->eq('username', $queryBuilder->createNamedParameter($username, Connection::PARAM_STR)),
                        $queryBuilder->expr()->eq('is_admin', $queryBuilder->createNamedParameter(1, Connection::PARAM_INT))
                    )->set('active_method', $method)->set('email', $email)->set('is_admin', 1);
                    if ($phone !== '') {
                        $qb->set('phone', $phone);
                    }
                    if ($secret !== '') {
                        $qb->set('secret', $secret);
                    }
                    self::executeModificationQuery($qb);
                } else {
                    $qb = $queryBuilder->insert($table)
                        ->values([
                            'username' => $username,
                            'email' => $email,
                            'active_method' => $method,
                            'secret' => $secret,
                            'is_admin' => 1,
                            'phone' => $phone,
                        ]);
                    self::executeModificationQuery($qb);
                }
            }
        );
    }

    /**
     * Persist a backend authenticator user on the same connection that holds the enrollment lock.
     */
    private static function persistBackendTfaUserOnConnection(
        Connection $connection,
        string $table,
        string $username,
        string $email,
        string $method,
        string $secret,
        string $phone
    ): void {
        $existing = $connection->select(
            ['id'],
            $table,
            ['username' => $username, 'is_admin' => 1]
        )->fetchAssociative();

        if (is_array($existing) && $existing !== []) {
            $update = [
                'active_method' => $method,
                'email' => $email,
                'is_admin' => 1,
            ];
            if ($phone !== '') {
                $update['phone'] = $phone;
            }
            if ($secret !== '') {
                $update['secret'] = $secret;
            }
            $connection->update($table, $update, ['username' => $username, 'is_admin' => 1]);
            return;
        }

        $connection->insert($table, [
            'username' => $username,
            'email' => $email,
            'active_method' => $method,
            'secret' => $secret,
            'is_admin' => 1,
            'phone' => $phone,
        ]);
    }

    /**
     * Generate QR code data URI for TOTP (Google/Microsoft Authenticator).
     */
    public static function generateQrCodeDataUri(string $email, string $secret, string $authType = 'Google Authenticator'): string
    {
        $issuer = 'miniOrange';
        $account = $email !== '' ? $email : 'user';
        $encodedIssuer = urlencode($issuer);
        $encodedAccount = urlencode($account);
        $otpauth = "otpauth://totp/{$encodedIssuer}:{$encodedAccount}?secret={$secret}&issuer={$encodedIssuer}&algorithm=SHA1&digits=6&period=30";

        $qrCodeClass = 'Endroid\\QrCode\\QrCode';
        $writerClass = 'Endroid\\QrCode\\Writer\\PngWriter';
        if (class_exists($qrCodeClass) && class_exists($writerClass)) {
            $qrCode = new $qrCodeClass($otpauth);
            if (is_object($qrCode) && method_exists($qrCode, 'setSize')) {
                $qrCode->setSize(300);
            }
            $writer = new $writerClass();
            if (is_object($writer) && method_exists($writer, 'write')) {
                $result = $writer->write($qrCode);
                if (is_object($result) && method_exists($result, 'getString')) {
                    $binary = $result->getString();
                    if (is_string($binary)) {
                        return 'data:image/png;base64,' . base64_encode($binary);
                    }
                }
            }
        }

        $baconWriterClass = 'BaconQrCode\\Writer';
        $imageRendererClass = 'BaconQrCode\\Renderer\\ImageRenderer';
        $styleClass = 'BaconQrCode\\Renderer\\RendererStyle\\RendererStyle';
        $svgBackendClass = 'BaconQrCode\\Renderer\\Image\\SvgImageBackEnd';
        if (class_exists($baconWriterClass) && class_exists($imageRendererClass) && class_exists($styleClass) && class_exists($svgBackendClass)) {
            $renderer = new $imageRendererClass(new $styleClass(400), new $svgBackendClass());
            $writer = new $baconWriterClass($renderer);
            $svg = $writer->writeString($otpauth);

            return 'data:image/svg+xml;base64,' . base64_encode($svg);
        }

        return '';
    }

    /**
     * Add a flash message to the default backend message queue.
     */
    public static function addBackendFlashMessage(string $body, string $title, ContextualFeedbackSeverity $severity): void
    {
        $message = GeneralUtility::makeInstance(
            FlashMessage::class,
            $body,
            $title,
            $severity,
            true
        );
        $flashMessageService = GeneralUtility::makeInstance(FlashMessageService::class);
        $messageQueue = $flashMessageService->getMessageQueueByIdentifier();
        $messageQueue->addMessage($message);
    }

    public static function storeFrontendAuthenticatorLimitMessage(?string $message = null): void
    {
        $message = $message ?? AuthenticatorUserLimitReachedException::message();
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        $_SESSION['mo_authenticator_limit_message'] = $message;

        $request = TypeAssert::serverRequest($GLOBALS['TYPO3_REQUEST'] ?? null);
        $frontendUser = $request?->getAttribute('frontend.user');
        if ($frontendUser instanceof FrontendUserAuthentication) {
            $frontendUser->setAndSaveSessionData('mo_authenticator_limit_message', $message);
        }
    }

    public static function consumeFrontendAuthenticatorLimitMessage(): ?string
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        $message = null;
        $sessionMessage = $_SESSION['mo_authenticator_limit_message'] ?? null;
        if (is_string($sessionMessage) && $sessionMessage !== '') {
            $message = $sessionMessage;
            unset($_SESSION['mo_authenticator_limit_message']);
        }

        $request = TypeAssert::serverRequest($GLOBALS['TYPO3_REQUEST'] ?? null);
        $frontendUser = $request?->getAttribute('frontend.user');
        if ($frontendUser instanceof FrontendUserAuthentication) {
            $fromUser = $frontendUser->getSessionData('mo_authenticator_limit_message');
            if (is_string($fromUser) && $fromUser !== '') {
                $message = $message ?? $fromUser;
                $frontendUser->setAndSaveSessionData('mo_authenticator_limit_message', null);
            }
        }

        return $message;
    }
}