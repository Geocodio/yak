<?php

namespace App\Channels\Linear;

class IdentifierExtractor
{
    public static function firstFrom(?string $text): ?string
    {
        if ($text === null || $text === '') {
            return null;
        }

        if (preg_match('/\b([A-Z]{2,6}-\d+)\b/', $text, $m) === 1) {
            return $m[1];
        }

        return null;
    }
}
