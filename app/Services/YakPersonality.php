<?php

namespace App\Services;

use App\Ai\Agents\PersonalityAgent;
use App\Enums\NotificationType;
use Illuminate\Support\Facades\Log;

class YakPersonality
{
    /** @var array<string, string> */
    private const FALLBACKS = [
        'acknowledgment' => 'On it — {context} 🐃',
        'progress' => '{context} ⏳',
        'clarification' => 'Need some input: {context} ❓',
        'retry' => 'Retrying — {context} 🔄',
        'result' => '{context} ✅',
        'error' => '{context} 🚨',
        'expiry' => 'This one timed out. ⏰',
    ];

    public static function generate(NotificationType $type, string $context): string
    {
        return self::generateWithTimeout($type, $context, null);
    }

    /**
     * Generate a personality message, but bound the underlying HTTP
     * call to `$timeoutSeconds` so callers on a tight SLA (e.g. the
     * Linear webhook's 10-second response budget) don't stall. On
     * timeout or any error, falls back to the static template.
     */
    public static function generateWithTimeout(NotificationType $type, string $context, ?int $timeoutSeconds): string
    {
        $apiKey = (string) config('ai.providers.anthropic.key');

        if ($apiKey === '') {
            return self::fallback($type, $context);
        }

        try {
            $response = PersonalityAgent::make($type->value, $context)
                ->prompt('Generate the notification message.', timeout: $timeoutSeconds);
            $text = trim((string) $response);

            return $text !== '' ? $text : self::fallback($type, $context);
        } catch (\Throwable $e) {
            Log::warning('YakPersonality: agent call failed', [
                'error' => $e->getMessage(),
            ]);

            return self::fallback($type, $context);
        }
    }

    public static function fallback(NotificationType $type, string $context): string
    {
        $template = self::FALLBACKS[$type->value];

        return str_replace('{context}', $context, $template);
    }
}
