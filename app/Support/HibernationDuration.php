<?php

namespace App\Support;

final class HibernationDuration
{
    /** @var array<string, int> */
    private const UNIT_MINUTES = [
        'm' => 1,
        'h' => 60,
        'd' => 1440,
        'w' => 10080,
    ];

    /**
     * Parse shorthand like "30m", "3h", "2d", "5w", or compound "1w2d"
     * into total minutes. Returns null for empty or invalid input.
     */
    public static function toMinutes(string $input): ?int
    {
        $normalized = strtolower(trim($input));

        if ($normalized === '' || ! preg_match('/^(\d+[mhdw])+$/', $normalized)) {
            return null;
        }

        preg_match_all('/(\d+)([mhdw])/', $normalized, $matches, PREG_SET_ORDER);

        $total = 0;
        foreach ($matches as $match) {
            $total += (int) $match[1] * self::UNIT_MINUTES[$match[2]];
        }

        return $total > 0 ? $total : null;
    }

    /**
     * Render minutes to a readable label, e.g. "3 days", "12 hours".
     */
    public static function humanize(int $minutes): string
    {
        $labels = ['w' => 'week', 'd' => 'day', 'h' => 'hour'];

        foreach ($labels as $unit => $label) {
            $size = self::UNIT_MINUTES[$unit];

            if ($minutes >= $size && $minutes % $size === 0) {
                $value = intdiv($minutes, $size);

                return $value . ' ' . $label . ($value === 1 ? '' : 's');
            }
        }

        return $minutes . ' minute' . ($minutes === 1 ? '' : 's');
    }

    /**
     * Render minutes to the shorthand the input field accepts, e.g. "3d".
     */
    public static function toShorthand(int $minutes): string
    {
        foreach (['w', 'd', 'h', 'm'] as $unit) {
            $size = self::UNIT_MINUTES[$unit];

            if ($minutes >= $size && $minutes % $size === 0) {
                return intdiv($minutes, $size) . $unit;
            }
        }

        return $minutes . 'm';
    }
}
