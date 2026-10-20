<?php

use App\Services\RiskProfileVerdict;
use App\Support\CodeOwners;

/**
 * @return list<array{name: string, risk: string, paths: list<string>}>
 */
function verdictAreas(): array
{
    return [
        ['name' => 'Docs', 'risk' => 'low', 'paths' => ['docs/**']],
        ['name' => 'Kernel', 'risk' => 'high', 'paths' => ['app/Http/Kernel.php']],
    ];
}

/**
 * @param  list<array<string, string>>  $changed
 * @return array<string, mixed>
 */
function evaluateVerdict(array $changed, ?CodeOwners $codeOwners = null, int $listingCap = 300): array
{
    return app(RiskProfileVerdict::class)->evaluate($changed, verdictAreas(), $codeOwners ?? CodeOwners::empty(), $listingCap, 'Listing is capped.');
}

it('holds a renamed file whose old path is in a high area', function () {
    $verdict = evaluateVerdict([['filename' => 'docs/kernel.md', 'previous_filename' => 'app/Http/Kernel.php']]);

    expect($verdict['isHeld'])->toBeTrue()
        ->and($verdict['heldAreas'])->toBe([['index' => 1, 'name' => 'Kernel', 'risk' => 'high']])
        ->and($verdict['files'][0])->toMatchArray(['path' => 'docs/kernel.md', 'previousPath' => 'app/Http/Kernel.php', 'isHeld' => true])
        ->and($verdict['files'][0]['area']['name'])->toBe('Kernel');
});

it('does not hold a change that matches no area', function () {
    $verdict = evaluateVerdict([['filename' => 'app/Support/Slug.php'], ['filename' => 'docs/readme.md']]);

    expect($verdict)->toMatchArray(['isHeld' => false, 'heldAreas' => [], 'asks' => [], 'capReason' => null, 'holdReason' => null, 'isFloorHeld' => false, 'isUncheckedHeld' => false]);
});

it('holds a change at the listing cap', function () {
    $verdict = evaluateVerdict([['filename' => 'a.txt'], ['filename' => 'b.txt'], ['filename' => 'c.txt']], listingCap: 3);

    expect($verdict)->toMatchArray(['isHeld' => true, 'capReason' => 'listing', 'capMessage' => 'Listing is capped.', 'holdReason' => 'Listing is capped.']);
});

it('ranks owners by held files', function () {
    $codeOwners = CodeOwners::parse("app/Http/Kernel.php @kernel-owner @shared\nCLAUDE.md @shared\n");

    $verdict = evaluateVerdict([['filename' => 'app/Http/Kernel.php'], ['filename' => 'CLAUDE.md'], ['filename' => 'docs/a.md']], $codeOwners);

    expect($verdict['asks'])->toBe(['@shared', '@kernel-owner'])
        ->and($verdict['isFloorHeld'])->toBeTrue()
        ->and(array_column($verdict['files'], 'path'))->toBe(['CLAUDE.md', 'app/Http/Kernel.php', 'docs/a.md']);
});
