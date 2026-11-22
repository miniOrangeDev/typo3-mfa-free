<?php

namespace Miniorange\Otp\Helper;

use Miniorange\Otp\Helper\TypeAssert;
use TYPO3\CMS\Core\Messaging\FlashMessage;
use TYPO3\CMS\Core\Messaging\Renderer\ListRenderer;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class CustomerMo
{
    public ?string $email = null;
    public ?string $phone = null;

    public function create_customer(string $email, string $password): string|false
    {
        $url = Constants::HOSTNAME . '/moas/rest/customer/add';
        $this->email = $email;
        $fields = [
            'companyName' => $_SERVER['SERVER_NAME'] ?? '',
            'areaOfInterest' => Constants::AREA_OF_INTEREST,
            'email' => $this->email,
            'password' => $password,
        ];
        $field_string = json_encode($fields);
        if ($field_string === false) {
            return false;
        }

        $ch = $this->prepareCurlOptions($url, $field_string);
        curl_setopt(
            $ch,
            CURLOPT_HTTPHEADER,
            [
                'Content-Type: application/json',
                'charset: UTF - 8',
                'Authorization: Basic',
            ]
        );

        $response = curl_exec($ch);
        error_log('create_customer response : ' . print_r($response, true));

        if (curl_errno($ch)) {
            error_log('cURL error: ' . curl_error($ch));
        }

        curl_close($ch);
        return is_string($response) ? $response : false;
    }

    private function prepareCurlOptions(string $url, string $field_string): \CurlHandle
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_ENCODING, '');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_AUTOREFERER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 10);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $field_string);

        $proxy_needed = MoUtilities::fetch_cust('proxy_needed');
        if ($proxy_needed) {
            $proxy_settings = MoUtilities::fetch_cust('proxy_settings');
            $proxy = !empty($proxy_settings) ? json_decode($proxy_settings, true) : [];
            if (!is_array($proxy)) {
                $proxy = [];
            }
            $proxy_host_name = isset($proxy['proxy_host_name']) && is_string($proxy['proxy_host_name'])
                ? $proxy['proxy_host_name']
                : '';
            $portNumber = TypeAssert::intValue($proxy['port_number'] ?? null);
            $username = isset($proxy['username']) && is_string($proxy['username']) ? $proxy['username'] : '';
            $password = isset($proxy['password']) && is_string($proxy['password'])
                ? base64_decode($proxy['password'])
                : '';

            if ($proxy_host_name !== '') {
                curl_setopt($ch, CURLOPT_PROXY, $proxy_host_name);
                curl_setopt($ch, CURLOPT_PROXYPORT, $portNumber);
                curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
                curl_setopt($ch, CURLOPT_PROXYUSERPWD, $username . ':' . $password);
            }
        }

        return $ch;
    }

    public function submit_contact(string $email, string $phone, string $query): ?string
    {
        error_log(' TYPO3 SUPPORT QUERY : ');

        $url = Constants::HOSTNAME . '/moas/rest/customer/contact-us';
        $ch = curl_init($url);
        if ($ch === false) {
            return null;
        }

        $query = 'Area of Interest : ' . Constants::AREA_OF_INTEREST . " \n Query : " . $query;
        $customerKey = MoUtilities::fetch_cust(Constants::CUSTOMER_KEY) ?? '';
        $apiKey = MoUtilities::fetch_cust(Constants::CUSTOMER_API_KEY) ?? '';

        if ($customerKey === '') {
            $customerKey = '';
            $apiKey = '';
        }

        $currentTimeInMillis = round(microtime(true) * 1000);
        $stringToHash = $customerKey . number_format($currentTimeInMillis, 0, '', '') . $apiKey;
        $hashValue = hash('sha512', $stringToHash);
        $customerKeyHeader = 'Customer-Key: ' . $customerKey;
        $timestampHeader = 'Timestamp: ' . number_format($currentTimeInMillis, 0, '', '');
        $authorizationHeader = 'Authorization: ' . $hashValue;

        $serverName = TypeAssert::stringValue($_SERVER['SERVER_NAME'] ?? null);
        $content = '<div >Hello, <br><br><b>Company :</b><a href="' . $serverName . '" target="_blank" >'
            . $serverName . '</a><br><br><b>Phone Number :</b>' . $phone
            . '<br><br><b>Email :<a href="mailto:' . $email . '" target="_blank">' . $email
            . '</a></b><br><br><b>Query: ' . $query . '</b></div>';

        $fields = [
            'email' => $email,
            'phone' => $phone,
            'query' => $query,
            'ccEmail' => 'magentosupport@xecurify.com',
        ];

        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            $customerKeyHeader,
            $timestampHeader,
            $authorizationHeader,
        ]);

        $field_string = json_encode($fields);
        if ($field_string === false) {
            curl_close($ch);
            return null;
        }

        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_ENCODING, '');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_AUTOREFERER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 10);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $field_string);
        $content = curl_exec($ch);

        if (curl_errno($ch)) {
            $message = GeneralUtility::makeInstance(
                FlashMessage::class,
                'CURL ERROR',
                'Error',
                ContextualFeedbackSeverity::ERROR,
                true
            );
            $messageArray = [$message];
            $out = GeneralUtility::makeInstance(ListRenderer::class)->render($messageArray);
            echo $out;
            curl_close($ch);
            return null;
        }

        curl_close($ch);
        return is_string($content) ? $content : null;
    }

    public function check_customer(string $email, string $password): string|false
    {
        $url = Constants::HOSTNAME . '/moas/rest/customer/check-if-exists';
        $fields = [
            'email' => $email,
        ];
        $field_string = json_encode($fields);
        if ($field_string === false) {
            return false;
        }

        $ch = $this->prepareCurlOptions($url, $field_string);
        curl_setopt(
            $ch,
            CURLOPT_HTTPHEADER,
            [
                'Content-Type: application/json',
                'charset: UTF - 8',
                'Authorization: Basic',
            ]
        );

        $response = curl_exec($ch);
        error_log('check_customer response : ' . print_r($response, true));

        if (curl_errno($ch)) {
            error_log('cURL error: ' . curl_error($ch));
        }
        curl_close($ch);

        return is_string($response) ? $response : false;
    }

    public function get_customer_key(string $email, string $password): string|false
    {
        $url = Constants::HOSTNAME . '/moas/rest/customer/key';
        $fields = [
            'email' => $email,
            'password' => $password,
        ];
        $field_string = json_encode($fields);
        if ($field_string === false) {
            return false;
        }

        $ch = $this->prepareCurlOptions($url, $field_string);
        curl_setopt(
            $ch,
            CURLOPT_HTTPHEADER,
            [
                'Content-Type: application/json',
                'charset: UTF - 8',
                'Authorization: Basic',
            ]
        );

        $response = curl_exec($ch);
        error_log('get_customer_key response : ' . print_r($response, true));

        if (curl_errno($ch)) {
            error_log('cURL error: ' . curl_error($ch));
        }
        curl_close($ch);

        return is_string($response) ? $response : false;
    }
}
