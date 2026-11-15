<?php

use Illuminate\Support\Facades\File;
use Tests\Support\VisibilityRoutes;

/**
 * @param  array<string, string>  $files  file name => PHP source of the returned array
 */
function withGroupFolder(array $files, Closure $callback): void
{
    $directory = sys_get_temp_dir() . '/app-route-groups-' . bin2hex(random_bytes(4));
    File::makeDirectory($directory);

    try {
        foreach ($files as $name => $source) {
            File::put("{$directory}/{$name}", "<?php\n\nreturn {$source};\n");
        }

        $callback($directory);
    } finally {
        File::deleteDirectory($directory);
    }
}

it('reads nothing from a missing or empty folder', function () {
    withGroupFolder([], function (string $empty): void {
        expect(VisibilityRoutes::groupsIn($empty . '/missing'))->toBe([])
            ->and(VisibilityRoutes::groupsIn($empty))->toBe([])
            ->and(VisibilityRoutes::groupNames('global', $empty))->toBe([])
            ->and(VisibilityRoutes::groupProbes($empty))->toBe([])
            ->and(VisibilityRoutes::groupHeaders($empty))->toBe([])
            ->and(VisibilityRoutes::groupWorlds($empty))->toBe([]);
    });
});

it('merges names, probes and headers in file name order', function () {
    withGroupFolder([
        'beta.php' => "['appThreads' => ['app.threads.sample-b'], 'sweepTables' => ['threads']]",
        'alpha.php' => "['appThreads' => ['app.threads.sample-a'], 'appThreadProbes' => ['app.threads.sample-a' => Tests\\Support\\VisibilityRoutes::probe('GET', '/threads/{thread}/sample-a', false)], 'sweepHeaders' => ['Origin' => 'https://yak.example.com']]",
    ], function (string $directory): void {
        expect(array_keys(VisibilityRoutes::groupsIn($directory)))->toBe(['alpha', 'beta'])
            ->and(VisibilityRoutes::groupNames('appThreads', $directory))->toBe(['app.threads.sample-a', 'app.threads.sample-b'])
            ->and(VisibilityRoutes::groupProbes($directory))->toBe(['app.threads.sample-a' => VisibilityRoutes::probe('GET', '/threads/{thread}/sample-a', false)])
            ->and(VisibilityRoutes::groupHeaders($directory))->toBe(['Origin' => 'https://yak.example.com'])
            ->and(VisibilityRoutes::groupNames('sweepTables', $directory))->toBe(['threads']);
    });
});

it('refuses a route name claimed by two groups', function () {
    withGroupFolder([
        'alpha.php' => "['appThreads' => ['app.threads.sample']]",
        'beta.php' => "['classified' => ['app.threads.sample']]",
    ], function (string $directory): void {
        expect(fn () => VisibilityRoutes::groupNames('appThreads', $directory))
            ->toThrow(InvalidArgumentException::class, 'app.threads.sample')
            ->and(fn () => VisibilityRoutes::groupNames('appThreads', $directory))
            ->toThrow(InvalidArgumentException::class, 'alpha.php')
            ->and(fn () => VisibilityRoutes::groupNames('appThreads', $directory))
            ->toThrow(InvalidArgumentException::class, 'beta.php');
    });
});

it('refuses an unknown key and a non-list', function () {
    withGroupFolder(['alpha.php' => "['apThreads' => []]"], function (string $directory): void {
        expect(fn () => VisibilityRoutes::groupsIn($directory))
            ->toThrow(InvalidArgumentException::class, 'apThreads')
            ->and(fn () => VisibilityRoutes::groupsIn($directory))
            ->toThrow(InvalidArgumentException::class, 'alpha.php');
    });

    withGroupFolder(['beta.php' => "['global' => 'app.me']"], function (string $directory): void {
        expect(fn () => VisibilityRoutes::groupsIn($directory))
            ->toThrow(InvalidArgumentException::class, 'beta.php');
    });
});

it('collects world closures', function () {
    withGroupFolder([
        'alpha.php' => "['sweepWorld' => fn (array \$world): array => ['{sample}' => (string) \$world['repository']->id]]",
    ], function (string $directory): void {
        $worlds = VisibilityRoutes::groupWorlds($directory);

        expect($worlds)->toHaveCount(1)
            ->and($worlds[0])->toBeInstanceOf(Closure::class);
    });
});

it('keeps the shipped group folder free of duplicate names', function () {
    expect(fn () => VisibilityRoutes::groupsIn())->not->toThrow(InvalidArgumentException::class);
});
