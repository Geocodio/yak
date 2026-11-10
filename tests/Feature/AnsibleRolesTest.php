<?php

use Symfony\Component\Yaml\Yaml;

it('gives the telemetryd data directory to the user the image runs as', function () {
    $tasks = Yaml::parseFile(base_path('ansible/roles/telemetryd/tasks/main.yml'));
    $directory = collect($tasks)->firstWhere('name', 'Ensure telemetryd data directory exists')['ansible.builtin.file'];

    expect((string) $directory['owner'])->toBe('10001')
        ->and((string) $directory['group'])->toBe('10001');
});

it('gives the redis data directory to the user redis runs as, so a deploy never takes it back', function () {
    $tasks = Yaml::parseFile(base_path('ansible/roles/redis/tasks/main.yml'));
    $directory = collect($tasks)->firstWhere('name', 'Ensure Redis data directory exists')['ansible.builtin.file'];

    expect((string) $directory['owner'])->toBe('999')
        ->and((string) $directory['group'])->toBe('1000');
});

it('moves leftover database jobs to redis on every deploy, before tasks resume', function () {
    $names = collect(Yaml::parseFile(base_path('ansible/roles/yak-container/tasks/main.yml')))->pluck('name')->values();

    $move = $names->search('Move jobs left on the database queue to Redis');

    expect($move)->not->toBeFalse()
        ->and($move)->toBeGreaterThan($names->search('Clear drain flag so workers resume'))
        ->and($move)->toBeLessThan($names->search('Resume tasks a drain force-failed for this deploy'));
});

it('keeps the redis password in a config file only the redis user can read', function () {
    $tasks = collect(Yaml::parseFile(base_path('ansible/roles/redis/tasks/main.yml')));
    $config = $tasks->firstWhere('name', 'Write Redis config')['ansible.builtin.template'];
    $container = $tasks->firstWhere('name', 'Run Redis container')['community.docker.docker_container'];

    expect($config['src'])->toBe('redis.conf.j2')
        ->and((string) $config['mode'])->toBe('0600')
        ->and((string) $config['owner'])->toBe('999')
        ->and((string) $config['group'])->toBe('1000')
        ->and($container['command'])->not->toContain('requirepass')
        ->and($container['command'])->toContain('redis.conf');
});

it('caps redis memory without evicting queued jobs', function () {
    $template = file_get_contents(base_path('ansible/roles/redis/templates/redis.conf.j2'));

    expect($template)->toContain('requirepass {{ redis_password }}')
        ->toContain('maxmemory {{ redis_maxmemory }}')
        ->toContain('maxmemory-policy noeviction')
        ->toContain('appendonly yes');
});
