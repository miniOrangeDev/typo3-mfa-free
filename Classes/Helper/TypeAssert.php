<?php

declare(strict_types=1);

namespace Miniorange\Otp\Helper;

use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Site\Entity\Site;

final class TypeAssert
{
    public static function backendUser(): ?BackendUserAuthentication
    {
        $beUser = $GLOBALS['BE_USER'] ?? null;

        return $beUser instanceof BackendUserAuthentication ? $beUser : null;
    }

    public static function requireBackendUser(): BackendUserAuthentication
    {
        $beUser = self::backendUser();
        if ($beUser === null) {
            throw new \RuntimeException('Backend user is not available.');
        }

        return $beUser;
    }

    public static function serverRequest(mixed $request): ?ServerRequestInterface
    {
        return $request instanceof ServerRequestInterface ? $request : null;
    }

    /**
     * @return array<string, mixed>
     */
    public static function parsedBodyArray(mixed $parsedBody): array
    {
        return self::stringKeyedArray($parsedBody);
    }

    /**
     * @return array<string, mixed>
     */
    public static function stringKeyedArray(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $result = [];
        foreach ($value as $key => $item) {
            if (is_string($key)) {
                $result[$key] = $item;
            }
        }

        return $result;
    }

    public static function stringValue(mixed $value, string $default = ''): string
    {
        if (is_string($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return (string)$value;
        }

        return $default;
    }

    public static function intValue(mixed $value, int $default = 0): int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && is_numeric($value)) {
            return (int)$value;
        }

        return $default;
    }

    /**
     * @return array<string, mixed>
     */
    public static function decodeJsonArray(mixed $data): array
    {
        if (is_array($data)) {
            return self::stringKeyedArray($data);
        }
        if (!is_string($data) || $data === '') {
            return [];
        }
        $decoded = json_decode($data, true);

        return self::stringKeyedArray($decoded);
    }

    public static function stringFromRow(mixed $row, string $key, string $default = ''): string
    {
        if (!is_array($row)) {
            return $default;
        }

        return self::stringValue($row[$key] ?? null, $default);
    }

    /**
     * @return list<string>
     */
    public static function stringListFromArray(mixed $values): array
    {
        if (!is_array($values)) {
            return [];
        }
        $result = [];
        foreach ($values as $value) {
            if (is_string($value) && $value !== '') {
                $result[] = $value;
            } elseif (is_int($value) || is_float($value)) {
                $result[] = (string)$value;
            }
        }

        return $result;
    }

    /**
     * @return array<string>
     */
    public static function stringPathList(mixed $value): array
    {
        return self::stringListFromArray($value);
    }

    /**
     * @return array<string, mixed>
     */
    public static function extensionConfigurationView(mixed $config): array
    {
        $configArray = self::stringKeyedArray($config);
        $view = $configArray['view'] ?? [];

        return self::stringKeyedArray($view);
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function stringFromArray(array $data, string $key, string $default = ''): string
    {
        return self::stringValue($data[$key] ?? null, $default);
    }

    public static function htmlString(mixed $html): string
    {
        return is_string($html) ? $html : '';
    }

    public static function tcaEnableColumn(string $table, string $enableKey, string $default): string
    {
        $tca = $GLOBALS['TCA'] ?? null;
        if (!is_array($tca) || !isset($tca[$table]) || !is_array($tca[$table])) {
            return $default;
        }
        $ctrl = $tca[$table]['ctrl'] ?? null;
        if (!is_array($ctrl)) {
            return $default;
        }
        $enablecolumns = $ctrl['enablecolumns'] ?? null;
        if (!is_array($enablecolumns)) {
            return $default;
        }
        $column = $enablecolumns[$enableKey] ?? $default;

        return is_string($column) && $column !== '' ? $column : $default;
    }

    public static function siteIdentifier(mixed $site): ?string
    {
        return $site instanceof Site ? $site->getIdentifier() : null;
    }

    /**
     * @return list<int>
     */
    public static function positiveIntList(mixed $values): array
    {
        if (!is_array($values)) {
            $id = self::intValue($values);

            return $id > 0 ? [$id] : [];
        }
        $ids = [];
        foreach ($values as $value) {
            $id = self::intValue($value);
            if ($id > 0) {
                $ids[] = $id;
            }
        }

        return $ids;
    }
}
