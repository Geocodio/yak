<?php

namespace App\Services;

class ReviewRiskScorer
{
    public const VERSION = 1;

    /**
     * Model ratings are ordinal judgments, not measured probabilities.
     * A strong signal never cancels a dangerous or missing one.
     *
     * @param  array<string, mixed>  $signals
     * @return array{score: ?int, model_confidence: ?int, reasons: array<int, string>, components: array<string, int>}
     */
    public function assess(array $signals, int $minimumConfidence = 80): array
    {
        $reasons = [];
        $values = [];
        foreach (['impact', 'blast_radius', 'behavior_change', 'verification_strength', 'context_completeness'] as $key) {
            $signal = $signals[$key] ?? [];
            if (! is_array($signal) || ! is_int($signal['value'] ?? null)
                || $signal['value'] < 0 || $signal['value'] > 4
                || ! is_string($signal['explanation'] ?? null) || trim($signal['explanation']) === ''
                || ! is_array($signal['references'] ?? null) || $signal['references'] === []
                || array_filter($signal['references'], fn ($ref): bool => ! is_string($ref) || trim($ref) === '') !== []) {
                $reasons[] = "Missing or invalid risk signal: {$key}";
            } else {
                $values[$key] = $signal['value'];
            }
        }
        $confidence = $signals['model_confidence'] ?? null;
        if (! is_int($confidence) || $confidence < 0 || $confidence > 100) {
            $confidence = null;
        }
        if ($confidence === null || $confidence < $minimumConfidence) {
            $reasons[] = "Model confidence is missing or below {$minimumConfidence}; human review required.";
        }
        foreach (['uncertainties', 'human_review_reasons'] as $key) {
            if (! isset($signals[$key]) || ! is_array($signals[$key]) || $signals[$key] !== []) {
                $reasons[] = "Unresolved or unspecified {$key}.";
            }
        }
        $components = count($values) === 5 ? [
            'impact' => $values['impact'] * 25,
            'blast_radius' => $values['blast_radius'] * 20,
            'behavior_change' => $values['behavior_change'] * 15,
            'verification_gap' => (4 - $values['verification_strength']) * 20,
            'context_gap' => (4 - $values['context_completeness']) * 20,
        ] : [];

        return ['score' => $components === [] ? null : max($components), 'model_confidence' => $confidence,
            'reasons' => $reasons, 'components' => $components];
    }
}
