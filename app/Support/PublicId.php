<?php

namespace App\Support;

/**
 * Generates the short random id that stands in for a primary key in URLs: eight
 * characters of lowercase Crockford base32 (no i, l, o or u). An id is never all
 * digits, so a numeric URL segment always means a legacy primary key.
 */
final class PublicId
{
    public const ALPHABET = '0123456789abcdefghjkmnpqrstvwxyz';

    public const LENGTH = 8;

    /** A route segment is a public id, or a legacy numeric id that redirects or is refused. */
    public const ROUTE_PATTERN = '[0-9a-hjkmnp-tv-z]{8}|[0-9]+';

    public static function generate(): string
    {
        do {
            $publicId = '';

            for ($position = 0; $position < self::LENGTH; $position++) {
                $publicId .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
            }
        } while (ctype_digit($publicId));

        return $publicId;
    }
}
