<?php

namespace App\Services;

use App\Enums\NotificationType;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\View;

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
        $prompt = View::make('prompts.personality', [
            'type' => $type->value,
            'context' => $context,
        ])->render();

        $apiKey = (string) config('yak.anthropic_api_key');

        if ($apiKey === '') {
            return self::fallback($type, $context);
        }

        try {
            $response = Http::withHeaders([
                'x-api-key' => $apiKey,
                'anthropic-version' => '2023-06-01',
            ])
                ->timeout(10)
                ->post('https://api.anthropic.com/v1/messages', [
                    'model' => 'claude-haiku-4-5-20251001',
                    'max_tokens' => 150,
                    'messages' => [
                        ['role' => 'user', 'content' => $prompt],
                    ],
                ]);

            if ($response->successful()) {
                $text = trim($response->json('content.0.text', ''));

                if ($text !== '') {
                    return $text;
                }
            }

            Log::warning('YakPersonality: API returned unsuccessful response', [
                'status' => $response->status(),
            ]);

            return self::fallback($type, $context);
        } catch (\Throwable $e) {
            Log::warning('YakPersonality: API call failed', [
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
