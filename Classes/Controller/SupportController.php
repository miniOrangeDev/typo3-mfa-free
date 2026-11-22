<?php

declare(strict_types=1);

namespace Miniorange\Otp\Controller;

use Miniorange\Otp\Helper\CustomerMo;
use Miniorange\Otp\Helper\MoUtilities;
use Miniorange\Otp\Helper\TypeAssert;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;

/**
 * Support Controller
 *
 * Handles support form submissions and related functionality
 */
class SupportController extends ActionController
{
    /**
     * Handle AJAX support form submission
     */
    public function submitAction(): ResponseInterface
    {
        try {
            $email = TypeAssert::stringValue($this->request->getArgument('email'));
            $phone = TypeAssert::stringValue($this->request->getArgument('phone'));
            $query = TypeAssert::stringValue($this->request->getArgument('query'));

            // Validation
            if ($email === '' || $phone === '' || $query === '') {
                return $this->createJsonResponse(['success' => false, 'message' => 'All fields are required']);
            }

            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return $this->createJsonResponse(['success' => false, 'message' => 'Invalid email address']);
            }

            // Log the support request
            error_log('Support form submitted via AJAX: ' . json_encode([
                'email' => $email,
                'phone' => $phone,
                'query' => $query,
            ]));

            // Use the existing CustomerMo class to submit the contact form
            $customer = new CustomerMo();
            $response = TypeAssert::decodeJsonArray($customer->submit_contact($email, $phone, $query));

            if (isset($response['status']) && $response['status'] === 'SUCCESS') {
                return $this->createJsonResponse([
                    'success' => true,
                    'message' => 'Support query sent successfully! We will get in touch with you shortly.',
                ]);
            }

            return $this->createJsonResponse([
                'success' => false,
                'message' => 'Could not send query. Please try again later.',
            ]);
        } catch (\Exception $e) {
            error_log('Support form AJAX error: ' . $e->getMessage());

            return $this->createJsonResponse([
                'success' => false,
                'message' => 'Something went wrong. Please try again.',
            ]);
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private function createJsonResponse(array $data): ResponseInterface
    {
        $typo3Version = MoUtilities::getTypo3Version();
        if ($typo3Version >= 12) {
            return $this->responseFactory->createResponse()
                ->withHeader('Content-Type', 'application/json')
                ->withBody($this->streamFactory->createStream(json_encode($data) ?: '{}'));
        }

        return new JsonResponse($data);
    }
}
