<?php

declare(strict_types=1);

namespace Miniorange\Otp\Middleware;

use Miniorange\Otp\Helper\MoUtilities;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Messaging\FlashMessage;
use TYPO3\CMS\Core\Messaging\FlashMessageService;
use TYPO3\CMS\Core\Messaging\Renderer\ListRenderer;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Frontend\Authentication\FrontendUserAuthentication;

final class FrontendAuthenticatorLimitMessageMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $frontendUser = $request->getAttribute('frontend.user');
        if (!$frontendUser instanceof FrontendUserAuthentication || empty($frontendUser->user['uid'])) {
            return $handler->handle($request);
        }

        $message = MoUtilities::consumeFrontendAuthenticatorLimitMessage();
        if ($message === null) {
            return $handler->handle($request);
        }

        $flashMessage = GeneralUtility::makeInstance(
            FlashMessage::class,
            $message,
            '',
            ContextualFeedbackSeverity::ERROR,
            true
        );
        $flashMessageService = GeneralUtility::makeInstance(FlashMessageService::class);
        $flashMessageService->getMessageQueueByIdentifier('core.template.flashMessages')->addMessage($flashMessage);

        $pageRenderer = GeneralUtility::makeInstance(PageRenderer::class);
        $pageRenderer->addFooterData(
            GeneralUtility::makeInstance(ListRenderer::class)->render([$flashMessage])
        );

        return $handler->handle($request);
    }
}
