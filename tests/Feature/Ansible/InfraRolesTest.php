<?php

use Symfony\Component\Yaml\Yaml;

$ansibleYaml = fn (string $path): array => Yaml::parseFile(base_path($path));

$roleTask = fn (string $role, string $name): array => collect(Yaml::parseFile(base_path("ansible/roles/{$role}/tasks/main.yml")))->firstWhere('name', $name);

it('defines the broker and redis variables in group_vars', function () use ($ansibleYaml) {
    $vars = $ansibleYaml('ansible/group_vars/yak.yml');

    expect($vars)->toMatchArray([
        'yak_broker_user' => 'yak-broker',
        'yak_broker_uid' => 991,
        'yak_broker_gid' => 991,
        'yak_credentials_path' => '/var/lib/yak/credentials',
        'yak_auth_proxy_address' => '10.100.0.1:8787',
        'yak_auth_proxy_host_cidrs' => '172.16.0.0/12',
        'yak_container_www_data_uid' => 33,
        'redis_data_path' => '/var/lib/yak/redis',
        'redis_container_uid' => 999,
        'redis_container_gid' => 1000,
        'incus_version_pin' => '7.6',
    ]);
});

it('documents the session store key and Reverb keys in the vault example', function () use ($ansibleYaml) {
    $vars = $ansibleYaml('ansible/vault/secrets.example.yml');

    expect($vars)->toHaveKeys(['yak_session_store_key', 'reverb_app_id', 'reverb_app_key', 'reverb_app_secret'])
        ->and(file_get_contents(base_path('ansible/vault/secrets.example.yml')))->toContain('openssl rand -base64 32');
});

it('fails the playbook early without a 32 byte session store key', function () use ($ansibleYaml) {
    $playbook = $ansibleYaml('ansible/playbook.yml');
    $assert = collect($playbook[0]['pre_tasks'])->firstWhere('name', 'Validate required variables')['ansible.builtin.assert']['that'];
    $conditions = collect($assert)->filter(fn ($condition) => str_contains($condition, 'yak_session_store_key'))->implode("\n");

    expect($conditions)->toContain('b64decode | length == 32')
        ->and(collect($assert)->implode("\n"))->toContain('reverb_app_key')
        ->toContain('reverb_app_secret')
        ->toContain('reverb_app_id');
});

it('keeps redis data in a 0700 directory outside the yak home', function () use ($roleTask, $ansibleYaml) {
    $directory = $roleTask('redis', 'Ensure Redis data directory exists')['ansible.builtin.file'];

    expect((string) $directory['mode'])->toBe('0700')
        ->and($directory['path'])->toBe('{{ redis_data_path }}')
        ->and($ansibleYaml('ansible/group_vars/yak.yml')['redis_data_path'])->toStartWith('/var/lib/yak/')
        ->not->toStartWith('/home/yak');
});

it('fsyncs redis every second', function () {
    expect(file_get_contents(base_path('ansible/roles/redis/templates/redis.conf.j2')))->toContain('appendfsync everysec');
});

it('publishes redis on loopback for the broker', function () use ($roleTask) {
    $container = $roleTask('redis', 'Run Redis container')['community.docker.docker_container'];

    expect($container['ports'])->toContain('127.0.0.1:6379:6379');
});

it('moves redis data out of the old home location', function () use ($roleTask) {
    $move = $roleTask('redis', 'Move Redis data out of the old location');

    expect($move)->toHaveKey('when');
});

it('puts the session store key from the vault in the container env', function () {
    $env = file_get_contents(base_path('ansible/roles/yak-container/templates/env.j2'));

    expect($env)->toContain('YAK_SESSION_STORE_KEY={{ yak_session_store_key }}')
        ->toContain('YAK_THREADS_SESSION_STORE_PATH=/data/session-store');
});

it('points the app at the credential socket and auth proxy', function () {
    $env = file_get_contents(base_path('ansible/roles/yak-container/templates/env.j2'));

    expect($env)->toContain('YAK_THREADS_CREDENTIAL_SOCKET={{ yak_credentials_path }}/redeem.sock')
        ->toContain('YAK_THREADS_AUTH_PROXY_URL=http://{{ yak_auth_proxy_address }}');
});

it('configures Reverb behind Caddy on the in-container port', function () {
    $env = file_get_contents(base_path('ansible/roles/yak-container/templates/env.j2'));

    foreach ([
        'BROADCAST_CONNECTION=reverb',
        'REVERB_APP_ID={{ reverb_app_id }}',
        'REVERB_APP_KEY={{ reverb_app_key }}',
        'REVERB_APP_SECRET={{ reverb_app_secret }}',
        'VITE_REVERB_HOST={{ yak_domain }}',
        'VITE_REVERB_PORT=443',
        'VITE_REVERB_SCHEME=https',
        'REVERB_SCALING_ENABLED=true',
        'REVERB_HOST=127.0.0.1',
        'REVERB_PORT=8080',
        'REVERB_SCHEME=http',
        'REVERB_SERVER_HOST=127.0.0.1',
        'REVERB_SERVER_PORT=8080',
    ] as $line) {
        expect($env)->toContain($line);
    }
});

it('mounts the credential socket directory into the container', function () use ($roleTask) {
    $container = $roleTask('yak-container', 'Run Yak container')['community.docker.docker_container'];

    expect($container['volumes'])->toContain('{{ yak_credentials_path }}:{{ yak_credentials_path }}');
});
