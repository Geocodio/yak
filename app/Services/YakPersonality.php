<?php

namespace App\Services;

use App\Ai\Agents\PersonalityAgent;
use App\Enums\NotificationType;
use Illuminate\Support\Facades\Log;

class YakPersonality
{
    /** @var array<string, string> */
    private const FALLBACKS = [
        'acknowledgment' => 'On it! 🐃',
        'progress' => 'Still working on this. ⏳',
        'clarification' => 'Need some input: {context} ❓',
        'retry' => 'Retrying. 🔄',
        'result' => '{context} ✅',
        'error' => '{context} 🚨',
        'expiry' => 'This one timed out. ⏰',
    ];

    public static function generate(NotificationType $type, string $context): string
    {
        $apiKey = (string) config('ai.providers.anthropic.key');

        if ($apiKey === '') {
            return self::fallback($type, $context);
        }

        try {
            $response = PersonalityAgent::make($type->value, $context)->prompt('Generate the notification message.');
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
