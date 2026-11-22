<?php

declare(strict_types=1);

namespace Miniorange\Otp\Helper;

use RuntimeException;

final class AESEncryption
{
    private const CIPHER = 'aes-256-gcm';
    private const TAG_LENGTH = 16;

    public static function encrypt_data(?string $string): string
    {
        if ($string === null || $string === '') {
            return '';
        }

        $ivLength = openssl_cipher_iv_length(self::CIPHER);
        if ($ivLength < 1) {
            throw new RuntimeException(sprintf('Unable to determine the IV length for cipher "%s".', self::CIPHER));
        }

        $iv = random_bytes($ivLength);
        $tag = '';
        $ciphertext = openssl_encrypt($string, self::CIPHER, self::getEncryptionKey(), OPENSSL_RAW_DATA, $iv, $tag);
        if ($ciphertext === false) {
            throw new RuntimeException('Failed to encrypt data.');
        }

        return base64_encode($iv . $tag . $ciphertext);
    }

    public static function decrypt_data(?string $string): string
    {
        if ($string === null || $string === '') {
            return '';
        }

        $decoded = base64_decode($string, true);
        if ($decoded === false) {
            return '';
        }

        $ivLength = openssl_cipher_iv_length(self::CIPHER);
        if ($ivLength < 1 || strlen($decoded) < $ivLength + self::TAG_LENGTH) {
            return '';
        }

        $iv = substr($decoded, 0, $ivLength);
        $tag = substr($decoded, $ivLength, self::TAG_LENGTH);
        $ciphertext = substr($decoded, $ivLength + self::TAG_LENGTH);
        $plaintext = openssl_decrypt($ciphertext, self::CIPHER, self::getEncryptionKey(), OPENSSL_RAW_DATA, $iv, $tag);

        return $plaintext === false ? '' : $plaintext;
    }

    /**
     * Derives a 256-bit key from the TYPO3 install encryption key.
     */
    private static function getEncryptionKey(): string
    {
        $typo3ConfVars = $GLOBALS['TYPO3_CONF_VARS'] ?? null;
        $sysConfig = is_array($typo3ConfVars) ? ($typo3ConfVars['SYS'] ?? null) : null;
        $encryptionKey = is_array($sysConfig) && isset($sysConfig['encryptionKey']) && is_string($sysConfig['encryptionKey'])
            ? $sysConfig['encryptionKey']
            : '';

        if ($encryptionKey === '') {
            throw new RuntimeException('TYPO3 encryption key is not configured.');
        }

        return hash_hkdf('sha256', $encryptionKey, 32, 'miniorange_otp_encryption');
    }
}
