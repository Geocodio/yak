<?php

namespace App\Support;

final class SecretRedactor
{
    public const REPLACEMENT = '[redacted]';

    private const PATTERNS = [
        '/sk-ant-[A-Za-z0-9_-]{8,}/',
        '/yakph-[A-Za-z0-9_-]{20,}/',
        '/Bearer\s+[A-Za-z0-9._~+\/-]{20,}=*/',
    ];

    public static function redact(string $text): string
    {
        return preg_replace(self::PATTERNS, self::REPLACEMENT, $text) ?? $text;
    }

    /**
     * @param  array<array-key, mixed>  $secrets
     */
    public static function redactExact(string $text, array $secrets): string
    {
        $values = array_filter(array_map('strval', $secrets), fn (string $value): bool => strlen($value) >= 4);
        usort($values, fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        return self::redact(str_replace($values, self::REPLACEMENT, $text));
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    public static function redactArray(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_string($value)) {
                $data[$key] = self::redact($value);
            } elseif (is_array($value)) {
                $data[$key] = self::redactArray($value);
            }
        }

        return $data;
    }
}
