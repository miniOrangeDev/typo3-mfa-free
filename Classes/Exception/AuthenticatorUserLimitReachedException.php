<?php

declare(strict_types=1);

namespace Miniorange\Otp\Exception;

use RuntimeException;

final class AuthenticatorUserLimitReachedException extends RuntimeException
{
    public static function message(): string
    {
        return sprintf(
            'Transaction limit has been exceeded. Please contact your administrator.',
        );
    }

    public function __construct()
    {
        parent::__construct(self::message());
    }
}
