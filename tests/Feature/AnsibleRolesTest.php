<?php

use Symfony\Component\Yaml\Yaml;

it('gives the telemetryd data directory to the user the image runs as', function () {
    $tasks = Yaml::parseFile(base_path('ansible/roles/telemetryd/tasks/main.yml'));
    $directory = collect($tasks)->firstWhere('name', 'Ensure telemetryd data directory exists')['ansible.builtin.file'];

    expect((string) $directory['owner'])->toBe('10001')
        ->and((string) $directory['group'])->toBe('10001');
});

it('moves leftover database jobs to redis on every deploy, before tasks resume', function () {
    $names = collect(Yaml::parseFile(base_path('ansible/roles/yak-container/tasks/main.yml')))->pluck('name')->values();

    $move = $names->search('Move jobs left on the database queue to Redis');

    expect($move)->not->toBeFalse()
        ->and($move)->toBeGreaterThan($names->search('Clear drain flag so workers resume'))
        ->and($move)->toBeLessThan($names->search('Resume tasks a drain force-failed for this deploy'));
});
