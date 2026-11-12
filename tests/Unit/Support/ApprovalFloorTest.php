<?php

use App\Support\ApprovalFloor;
use Tests\TestCase;

uses(TestCase::class);

it('holds floor paths', function (string $path) {
    expect(ApprovalFloor::heldPaths([['filename' => $path]]))->toBe([$path]);
})->with([
    'Dockerfile',
    'docker/php/php.ini',
    '.github/workflows/ci.yml',
    'database/migrations/2026_x.php',
    'migrations/001.sql',
    'app/Modules/Billing/migrations/x.sql',
    '.env.production',
    'nested/dir/auth.json',
]);

it('holds a rename away from a floor path', function () {
    $files = [['filename' => 'docs/yak.yml', 'previous_filename' => '.yak/config.yml']];

    expect(ApprovalFloor::heldPaths($files))->toBe(['.yak/config.yml']);
});

it('lets ordinary files and dependency manifests through', function (string $path) {
    expect(ApprovalFloor::heldPaths([['filename' => $path]]))->toBe([]);
})->with([
    'app/Http/Controllers/HomeController.php',
    'resources/views/home.blade.php',
    'tests/Feature/HomeTest.php',
    'composer.lock',
    'package.json',
]);

it('returns exactly the held paths from a mixed list, deduplicated', function () {
    $files = [
        ['filename' => 'app/Models/User.php'],
        ['filename' => 'Dockerfile'],
        ['filename' => 'resources/js/app.tsx'],
        ['filename' => 'docs/yak.yml', 'previous_filename' => '.yak/config.yml'],
        ['filename' => '.yak/config.yml', 'previous_filename' => 'old/config.yml'],
        ['filename' => 'composer.lock'],
    ];

    expect(ApprovalFloor::heldPaths($files))->toBe(['Dockerfile', '.yak/config.yml']);
});
