<?php

use Symfony\Component\Yaml\Yaml;

$rolePath = fn (string $path): string => base_path("ansible/roles/yak-ssh/{$path}");

it('parses the role defaults, tasks and handlers', function () use ($rolePath) {
    $defaults = Yaml::parseFile($rolePath('defaults/main.yml'));

    expect($defaults)->toMatchArray([
        'yak_ssh_jump_port' => 2222,
        'yak_ssh_bridge_listen' => '127.0.0.1:8090',
        'yak_ssh_incus_bridge' => '10.100.0.1/24',
        'yak_ssh_wake_max_session' => '12h',
    ])->toHaveKeys(['yak_ssh_url', 'yak_ssh_internal_token', 'yak_ssh_allowed_origins']);

    expect(Yaml::parseFile($rolePath('tasks/main.yml')))->toBeArray()->not->toBeEmpty()
        ->and(Yaml::parseFile($rolePath('handlers/main.yml')))->toBeArray()->not->toBeEmpty();
});

it('renders a hardened jump sshd config', function () use ($rolePath) {
    $config = file_get_contents($rolePath('templates/yak-jump-sshd.conf.j2'));

    foreach ([
        'Port {{ yak_ssh_jump_port }}',
        'HostKey ',
        'PidFile ',
        'AllowUsers {{ yak_ssh_jump_user }}',
        'PasswordAuthentication no',
        'KbdInteractiveAuthentication no',
        'AuthorizedKeysFile none',
        'AuthorizedKeysCommand /usr/local/bin/yak-ssh-lookup %f',
        'AuthorizedKeysCommandUser {{ yak_ssh_lookup_user }}',
        'AllowTcpForwarding local',
        'PermitListen none',
        'PermitOpen *:22',
        'PermitTTY no',
        'X11Forwarding no',
        'AllowAgentForwarding no',
        'PermitTunnel no',
        'GatewayPorts no',
        'ForceCommand /bin/false',
    ] as $line) {
        expect($config)->toContain($line);
    }

    expect($config)->not->toContain('PermitTTY yes')->not->toContain('AllowTcpForwarding yes');
});

it('leaves the jump user unlocked so key-only sshd login works', function () use ($rolePath) {
    $task = collect(Yaml::parseFile($rolePath('tasks/main.yml')))
        ->first(fn (array $task): bool => ($task['ansible.builtin.user']['name'] ?? null) === '{{ yak_ssh_jump_user }}');

    expect($task['ansible.builtin.user']['password'] ?? null)->toBe('*');
});

it('hardens the wake and bridge units', function () use ($rolePath) {
    foreach (['yak-wake.service.j2', 'yak-termbridge.service.j2'] as $unit) {
        expect(file_get_contents($rolePath("templates/{$unit}")))
            ->toContain('NoNewPrivileges=yes')
            ->toContain('ProtectSystem=strict');
    }
});

it('keeps token files private and never touches the main sshd config', function () use ($rolePath) {
    $tasks = collect(Yaml::parseFile($rolePath('tasks/main.yml')));

    $tokenTask = $tasks->firstWhere('name', 'Write the internal token files');
    expect($tokenTask['ansible.builtin.copy']['mode'])->toBe('0600')
        ->and($tokenTask['no_log'])->toBeTrue();

    $touchesMainConfig = $tasks->contains(function (array $task): bool {
        foreach ($task as $module) {
            if (is_array($module) && (($module['dest'] ?? $module['path'] ?? null) === '/etc/ssh/sshd_config')) {
                return true;
            }
        }

        return false;
    });
    expect($touchesMainConfig)->toBeFalse();
});

it('pins the host sshd off the loopback addresses the wake proxy binds', function () use ($rolePath) {
    $tasks = collect(Yaml::parseFile($rolePath('tasks/main.yml')));
    $task = $tasks->firstWhere('name', 'Keep the host sshd off the sandbox wake addresses');

    expect($task['ansible.builtin.copy']['dest'])->toBe('/etc/ssh/sshd_config.d/10-yak-listen.conf')
        ->and($task['ansible.builtin.copy']['content'])->toContain('ListenAddress {{ yak_ssh_host_listen_address }}')->not->toContain('ListenAddress 0.0.0.0')
        ->and($task['notify'])->toBe('Restart host sshd')
        ->and(collect(Yaml::parseFile($rolePath('handlers/main.yml')))->pluck('name'))->toContain('Restart host sshd');
});

it('is wired into the playbook after incus and yak-broker with its tag', function () {
    $roles = collect(Yaml::parseFile(base_path('ansible/playbook.yml'))[0]['roles']);
    $names = $roles->pluck('role')->all();

    expect($roles->firstWhere('role', 'yak-ssh')['tags'])->toBe(['yak-ssh'])
        ->and($roles->firstWhere('role', 'yak-broker')['tags'])->toBe(['yak-broker'])
        ->and(array_search('yak-ssh', $names))->toBeGreaterThan(array_search('incus', $names))
        ->toBeGreaterThan(array_search('yak-broker', $names))
        ->and(array_search('yak-broker', $names))->toBeLessThan(array_search('yak-container', $names))
        ->toBeGreaterThan(array_search('redis', $names));
});

it('builds and installs the ssh binaries the units expect', function () use ($rolePath) {
    $tasks = collect(Yaml::parseFile($rolePath('tasks/main.yml')));

    $build = $tasks->firstWhere('name', 'Build the ssh binaries on the controller');
    expect($build)->not->toHaveKey('when')
        ->and($build['environment'])->toMatchArray(['CGO_ENABLED' => '0', 'GOOS' => 'linux', 'GOARCH' => 'amd64'])
        ->and(collect($build['loop'])->pluck('binary')->all())->toBe(['yak-wakeproxy', 'yak-termbridge', 'yak-ssh-lookup']);

    foreach ([
        'Install the wake proxy binary' => ['yak-wakeproxy', 'Restart yak-wake'],
        'Install the terminal bridge binary' => ['yak-termbridge', 'Restart yak-termbridge'],
        'Install the key lookup binary' => ['yak-ssh-lookup', null],
    ] as $name => [$binary, $handler]) {
        $copy = $tasks->firstWhere('name', $name);
        expect($copy['ansible.builtin.copy'])->toMatchArray(['mode' => '0755', 'owner' => 'root', 'group' => 'root'])
            ->and($copy['ansible.builtin.copy']['dest'])->toBe("{{ yak_ssh_bin_dir }}/{$binary}")
            ->and($copy['notify'] ?? null)->toBe($handler);
    }
});

it('passes the internal token to the app and documents it in the vault example', function () {
    expect(file_get_contents(base_path('ansible/roles/yak-container/templates/env.j2')))->toContain('YAK_SSH_INTERNAL_TOKEN={{ yak_ssh_internal_token')
        ->and(file_get_contents(base_path('ansible/vault/secrets.example.yml')))->toContain('yak_ssh_internal_token:');
});

it('names the termbridge origin allowlist variable the bridge reads', function () use ($rolePath) {
    $env = file_get_contents($rolePath('templates/yak-ssh.env.j2'));

    expect($env)->toContain('YAK_TERMBRIDGE_ORIGINS={{ yak_ssh_allowed_origins }}')
        ->not->toContain('YAK_TERMBRIDGE_ALLOWED_ORIGINS');
});

it('passes the bridge subnet and session cap to the wake proxy', function () use ($rolePath) {
    $env = file_get_contents($rolePath('templates/yak-ssh.env.j2'));

    expect($env)->toContain('YAK_INCUS_BRIDGE={{ yak_ssh_incus_bridge }}')
        ->toContain('YAK_WAKE_MAX_SESSION={{ yak_ssh_wake_max_session }}');
});

it('derives the bridge urls from yak_domain and hard-codes no host', function () use ($rolePath) {
    $defaults = Yaml::parseFile($rolePath('defaults/main.yml'));

    expect($defaults['yak_ssh_url'])->toBe('https://{{ yak_domain }}')
        ->and($defaults['yak_ssh_allowed_origins'])->toBe('https://{{ yak_domain }}');

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path('ansible'), FilesystemIterator::SKIP_DOTS)) as $file) {
        expect(file_get_contents($file->getPathname()))->not->toContain('geocodio.yak.build');
    }
});

it('gives the app the jump host and port that yak ssh hands to clients', function () {
    $env = file_get_contents(base_path('ansible/roles/yak-container/templates/env.j2'));

    expect($env)->toContain('YAK_SSH_JUMP_HOST={{ yak_ssh_jump_host | default(yak_domain) }}')
        ->toContain('YAK_SSH_JUMP_PORT={{ yak_ssh_jump_port | default(2222) }}')
        ->and(config('yak.ssh.jump_port'))->toBe(2222);
});
