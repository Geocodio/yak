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

it('names the group file that probes a core route again', function () {
    withGroupFolder([
        'alpha.php' => "['appThreadProbes' => ['app.threads.show' => Tests\\Support\\VisibilityRoutes::probe('GET', '/threads/{thread}', false)]]",
    ], function (string $directory): void {
        expect(fn () => VisibilityRoutes::appThreadProbes($directory))
            ->toThrow(InvalidArgumentException::class, 'Route group alpha.php probes a core route again: app.threads.show.');
    });
});

it('refuses a probe or header defined by two groups', function () {
    $probe = "Tests\\Support\\VisibilityRoutes::probe('GET', '/threads/{thread}/x', false)";

    withGroupFolder([
        'alpha.php' => "['appThreadProbes' => ['app.threads.x' => {$probe}], 'sweepHeaders' => ['Origin' => 'a']]",
        'beta.php' => "['appThreadProbes' => ['app.threads.x' => {$probe}]]",
    ], function (string $directory): void {
        expect(fn () => VisibilityRoutes::groupsIn($directory))
            ->toThrow(InvalidArgumentException::class, "Probe 'app.threads.x' is defined by two groups: alpha.php and beta.php.");
    });

    withGroupFolder([
        'alpha.php' => "['sweepHeaders' => ['Origin' => 'a']]",
        'beta.php' => "['sweepHeaders' => ['Origin' => 'b']]",
    ], function (string $directory): void {
        expect(fn () => VisibilityRoutes::groupsIn($directory))
            ->toThrow(InvalidArgumentException::class, "Header 'Origin' is defined by two groups: alpha.php and beta.php.");
    });
});

it('refuses values of the wrong shape', function (string $source) {
    withGroupFolder(['alpha.php' => "[{$source}]"], function (string $directory): void {
        expect(fn () => VisibilityRoutes::groupsIn($directory))
            ->toThrow(InvalidArgumentException::class, 'Route group alpha.php has the wrong shape under');
    });
})->with([
    'list-shaped probes' => ["'appThreadProbes' => [Tests\\Support\\VisibilityRoutes::probe('GET', '/x', false)]"],
    'probe without a path' => ["'appThreadProbes' => ['app.threads.x' => ['method' => 'GET', 'isDrive' => false, 'body' => []]]"],
    'non-string header' => ["'sweepHeaders' => ['Origin' => 1]"],
    'non-string list element' => ["'classified' => [1]"],
]);

it('keeps the shipped group folder free of collisions', function () {
    expect(fn () => VisibilityRoutes::groupsIn())->not->toThrow(InvalidArgumentException::class)
        ->and(fn () => VisibilityRoutes::appThreads())->not->toThrow(InvalidArgumentException::class)
        ->and(fn () => VisibilityRoutes::appThreadProbes())->not->toThrow(InvalidArgumentException::class)
        ->and(fn () => VisibilityRoutes::global())->not->toThrow(InvalidArgumentException::class);
});
