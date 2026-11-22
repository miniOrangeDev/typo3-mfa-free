<?php

declare(strict_types=1);

namespace Miniorange\Otp\Middleware;

use Miniorange\Otp\Exception\AuthenticatorUserLimitReachedException;
use Miniorange\Otp\Helper\TypeAssert;
use Miniorange\Otp\Helper\MoUtilities;
use Miniorange\Otp\Helper\Constants;
use Miniorange\Otp\Services\AuthenticatorEnrollmentLimiter;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\RedirectResponse;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class Backend2faMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $beUser = TypeAssert::backendUser();
        if ($beUser === null || empty($beUser->user['uid'])) {
            return $handler->handle($request);
        }

        if (strpos($request->getUri()->getPath(), '/backend/two-factor-login') !== false) {
            return $handler->handle($request);
        }

        if ($beUser->getSessionData('mo_2fa_verified') === true) {
            return $handler->handle($request);
        }

        $userSettings = $this->getUser2FASettings(TypeAssert::stringKeyedArray($beUser->user));

        if ($userSettings['enabled'] === true) {
            $methods = $userSettings['methods'];
            $connection = GeneralUtility::makeInstance(ConnectionPool::class)
                ->getConnectionForTable(Constants::TABLE_TFA_USERS);
            $limiter = GeneralUtility::makeInstance(AuthenticatorEnrollmentLimiter::class);
            if ($limiter->isTwoFactorLimitReached($connection)) {
                $beUser->setAndSaveSessionData('mo_2fa_verified', true);
                $beUser->uc['mo_2fa_verified'] = true;
                $beUser->writeUC();
                MoUtilities::addBackendFlashMessage(
                    AuthenticatorUserLimitReachedException::message(),
                    '',
                    ContextualFeedbackSeverity::ERROR
                );

                return $handler->handle($request);
            }

            $beUser->setAndSaveSessionData('mo_2fa_available_methods', $methods);
            $uriBuilder = GeneralUtility::makeInstance(UriBuilder::class);
            $redirectUri = $uriBuilder->buildUriFromRoute('backend');

            return new RedirectResponse((string)$redirectUri);
        }

        return $handler->handle($request);
    }

    /**
     * @param array<string, mixed> $user
     * @return array{enabled: bool, methods: list<string>}
     */
    private function getUser2FASettings(array $user): array
    {
        if (!MoUtilities::isCustomerLoggedIn()) {
            return ['enabled' => false, 'methods' => []];
        }

        $enabled = false;
        /** @var list<string> $methods */
        $methods = [];

        try {
            $qbSettings = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('miniorange_tfa_settings');
            $globalSetting = $qbSettings->select('*')
                ->from('miniorange_tfa_settings')
                ->where(
                    $qbSettings->expr()->eq('admin_2fa', $qbSettings->createNamedParameter(1))
                )
                ->setMaxResults(1)
                ->executeQuery()
                ->fetchAssociative();

            if (!is_array($globalSetting)) {
                return ['enabled' => false, 'methods' => []];
            }

            $enabled = true;

            $username = TypeAssert::stringFromRow($user, 'username');
            $qbUser = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('miniorange_tfa_users');
            $userRow = $qbUser->select('*')
                ->from('miniorange_tfa_users')
                ->where(
                    $qbUser->expr()->eq('username', $qbUser->createNamedParameter($username)),
                    $qbUser->expr()->eq('is_admin', $qbUser->createNamedParameter(1, Connection::PARAM_INT))
                )
                ->executeQuery()
                ->fetchAssociative();

            if (is_array($userRow)) {
                if (TypeAssert::intValue($userRow['skip_twofa'] ?? 0) === 1 || TypeAssert::intValue($userRow['disable_2fa'] ?? 0) === 1) {
                    return ['enabled' => false, 'methods' => []];
                }

                $configuredMethods = $userRow['configured_methods'] ?? '';
                if (is_string($configuredMethods) && $configuredMethods !== '') {
                    $methods = GeneralUtility::trimExplode(',', $configuredMethods, true);
                } elseif (is_string($userRow['active_method'] ?? null) && $userRow['active_method'] !== '') {
                    $methods[] = $userRow['active_method'];
                }
            } else {
                $adminMethod = $globalSetting['admin_2fa_method'] ?? '';
                if (is_string($adminMethod) && $adminMethod !== '') {
                    $methods = GeneralUtility::trimExplode(',', $adminMethod, true);
                }
            }

            $methods = array_map(static function (string $method): string {
                return strtoupper(trim($method));
            }, $methods);

            return [
                'enabled' => $enabled,
                'methods' => $methods,
            ];
        } catch (\Throwable $e) {
            return ['enabled' => false, 'methods' => []];
        }
    }
}
