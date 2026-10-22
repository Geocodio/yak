<?php

namespace App\Services\CoOwner;

use App\Ai\Agents\RiskAreaGuessAgent;
use App\Services\RiskProfileMatcher;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Responses\StructuredAgentResponse;

/**
 * Uses Haiku (via Laravel AI) to guess which held risk-profile areas a request
 * will touch, so a co-owner can be warned before any code is written.
 */
class RiskAreaGuesser
{
    public const LIKELIHOOD_THRESHOLD = 60;

    private const MAX_REQUEST_LENGTH = 4000;

    /**
     * Return the names of the held areas the request probably touches, in
     * profile order. Empty on any failure or when no API key is configured.
     *
     * @param  list<array{name: string, risk: string, rationale: string}>  $areas
     * @return list<string>
     */
    public function guess(string $request, array $areas): array
    {
        $held = array_values(array_filter(
            $areas,
            fn (array $area): bool => in_array($area['risk'], RiskProfileMatcher::HELD_RISKS, true),
        ));

        if (trim($request) === '' || (string) config('ai.providers.anthropic.key') === '' || $held === []) {
            return [];
        }

        $areaList = implode("\n", array_map(
            fn (array $area): string => "- {$area['name']} ({$area['risk']}): {$area['rationale']}",
            $held,
        ));
        $safeRequest = str_ireplace('</request>', '< /request>', mb_substr($request, 0, self::MAX_REQUEST_LENGTH));

        $prompt = "Areas:\n{$areaList}\n\n<request>\n{$safeRequest}\n</request>";

        try {
            /** @var StructuredAgentResponse $response */
            $response = RiskAreaGuessAgent::make()->prompt($prompt);

            $guessed = collect(is_array($response->structured['areas'] ?? null) ? $response->structured['areas'] : [])
                ->filter(fn (mixed $entry): bool => is_array($entry)
                    && is_string($entry['name'] ?? null)
                    && is_numeric($entry['likelihood'] ?? null)
                    && (int) $entry['likelihood'] >= self::LIKELIHOOD_THRESHOLD)
                ->pluck('name')
                ->all();

            return array_values(array_unique(array_filter(
                array_column($held, 'name'),
                fn (string $name): bool => in_array($name, $guessed, true),
            )));
        } catch (\Throwable $exception) {
            Log::warning('RiskAreaGuesser: guess failed', ['error' => $exception->getMessage()]);

            return [];
        }
    }
}
