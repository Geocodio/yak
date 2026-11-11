<?php

use Symfony\Component\Yaml\Yaml;

$rolePath = fn (string $path): string => base_path("ansible/roles/yak-ssh/{$path}");

it('parses the role defaults, tasks and handlers', function () use ($rolePath) {
    $defaults = Yaml::parseFile($rolePath('defaults/main.yml'));

    expect($defaults)->toMatchArray([
        'yak_ssh_jump_port' => 2222,
        'yak_ssh_bridge_listen' => '127.0.0.1:8090',
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
        'AllowTcpForwarding yes',
        'PermitTTY no',
        'X11Forwarding no',
        'AllowAgentForwarding no',
        'PermitTunnel no',
        'GatewayPorts no',
        'ForceCommand /bin/false',
    ] as $line) {
        expect($config)->toContain($line);
    }

    expect($config)->not->toContain('PermitTTY yes');
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

it('is not wired into the playbook yet', function () {
    expect(file_get_contents(base_path('ansible/playbook.yml')))->not->toContain('yak-ssh');
});
