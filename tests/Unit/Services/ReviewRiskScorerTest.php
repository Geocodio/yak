<?php

use App\Services\ReviewRiskScorer;

function riskSignals(): array
{
    $signals = ['model_confidence' => 90, 'uncertainties' => [], 'human_review_reasons' => []];
    foreach (['impact' => 1, 'blast_radius' => 1, 'behavior_change' => 1, 'verification_strength' => 3, 'context_completeness' => 3] as $key => $value) {
        $signals[$key] = ['value' => $value, 'explanation' => 'Inspected actual callers.', 'references' => ['app/Foo.php:42']];
    }

    return $signals;
}

it('does not let strong verification compensate for critical impact', function () {
    $signals = riskSignals();
    $signals['impact']['value'] = 4;
    $signals['verification_strength']['value'] = 4;
    $signals['context_completeness']['value'] = 4;
    $result = (new ReviewRiskScorer)->assess($signals);
    expect($result['score'])->toBe(100)->and($result['model_confidence'])->toBe(90);
});

it('returns unknown for missing malformed or uncited dimensions', function (string $case) {
    $signals = riskSignals();
    match ($case) {
        'missing' => $signals = [],
        'range' => $signals['impact']['value'] = -1,
        'type' => $signals['impact']['value'] = '0',
        'references' => $signals['impact']['references'] = [],
        'blank reference' => $signals['impact']['references'] = [''],
    };
    $result = (new ReviewRiskScorer)->assess($signals);
    expect($result['score'])->toBeNull()->and($result['reasons'])->not->toBeEmpty();
})->with(['missing', 'range', 'type', 'references', 'blank reference']);

it('uses confidence only as an escalation signal', function () {
    $signals = riskSignals();
    $signals['model_confidence'] = 100;
    $high = (new ReviewRiskScorer)->assess($signals);
    $signals['model_confidence'] = 30;
    $low = (new ReviewRiskScorer)->assess($signals);
    expect($high['score'])->toBe(25)->and($low['score'])->toBe(25)
        ->and($high['reasons'])->toBe([])->and($low['reasons'])->not->toBeEmpty();
});

it('escalates uncertainty independently of score', function () {
    $signals = riskSignals();
    $signals['uncertainties'] = ['Production caller is unavailable.'];
    expect((new ReviewRiskScorer)->assess($signals)['reasons'])->not->toBeEmpty();
});
