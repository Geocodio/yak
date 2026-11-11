<?php

use Illuminate\Support\Facades\Process;
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

    preg_match("/is match\\('([^']+)'\\)/", $conditions, $matches);
    $pattern = '#' . $matches[1] . '#';

    foreach (range(1, 200) as $ignored) {
        expect(preg_match($pattern, base64_encode(random_bytes(32))))->toBe(1);
    }

    expect(preg_match($pattern, base64_encode(random_bytes(31))))->toBe(0)
        ->and(preg_match($pattern, base64_encode(random_bytes(33))))->toBe(0)
        ->and(preg_match($pattern, str_repeat('*', 43) . '='))->toBe(0)
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
        ->toContain('YAK_THREADS_AUTH_PROXY_URL=http://{{ yak_auth_proxy_address }}')
        ->toContain('YAK_THREADS_CREDENTIAL_SOCKET_UID={{ yak_broker_uid }}')
        ->toContain('YAK_THREADS_AUTH_PROXY_VALIDATION_ADDRESS={{ yak_container_address }}')
        ->not->toContain('VALIDATION_CIDRS');
});

it('gives the Yak container a fixed address on a dedicated egress network', function () use ($ansibleYaml, $roleTask) {
    $vars = $ansibleYaml('ansible/group_vars/yak.yml');
    [$network, $prefix] = explode('/', $vars['yak_app_network_subnet']);
    $mask = ~((1 << (32 - (int) $prefix)) - 1) & 0xFFFFFFFF;

    expect($vars['yak_app_network_name'])->toBe('yak-app')
        ->and($prefix)->toBe('29')
        ->and(ip2long($vars['yak_container_address']) & $mask)->toBe(ip2long($network) & $mask)
        ->and($vars['yak_auth_proxy_host_cidrs'])->toBe('172.16.0.0/12');

    $ipam = $roleTask('yak-container', 'Ensure the Yak app network exists')['community.docker.docker_network'];
    expect($ipam['name'])->toBe('{{ yak_app_network_name }}')
        ->and($ipam['ipam_config'][0]['subnet'])->toBe('{{ yak_app_network_subnet }}');

    $networks = collect($roleTask('yak-container', 'Run Yak container')['community.docker.docker_container']['networks']);
    $app = $networks->firstWhere('name', '{{ yak_app_network_name }}');

    expect($networks->pluck('name'))->toContain('yak')
        ->and($app['ipv4_address'])->toBe('{{ yak_container_address }}')
        ->and($app['gw_priority'])->toBeGreaterThan(0);
});

it('fails the deploy when the container does not reach the auth proxy from its yak-app address', function () use ($roleTask) {
    $check = $roleTask('yak-container', 'Assert the Yak container reaches the auth proxy from its yak-app address');
    $names = collect(Yaml::parseFile(base_path('ansible/roles/yak-container/tasks/main.yml')))->pluck('name');

    expect(json_encode($check))->toContain('ip -4 route get')->toContain('yak_container_address')
        ->and($names->search($check['name']))->toBeGreaterThan($names->search('Wait for Yak container health'));
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

it('runs the broker as an unprivileged user in incus-admin', function () use ($roleTask) {
    $user = $roleTask('yak-broker', 'Create the broker user')['ansible.builtin.user'];

    expect($user)->toMatchArray([
        'groups' => 'incus-admin',
        'system' => true,
        'shell' => '/usr/sbin/nologin',
        'uid' => '{{ yak_broker_uid }}',
    ]);
});

it('makes the credential socket directory root-owned, setgid, in the broker group', function () use ($roleTask) {
    $directory = $roleTask('yak-broker', 'Create the credential socket directory')['ansible.builtin.file'];
    $env = file_get_contents(base_path('ansible/roles/yak-container/templates/env.j2'));

    expect($directory)->toMatchArray([
        'mode' => '2770',
        'owner' => 'root',
        'group' => '{{ yak_broker_user }}',
    ])->and($env)->toContain('YAK_THREADS_CREDENTIAL_SOCKET_DIRECTORY_GID={{ yak_broker_gid }}');
});

it('writes the broker environment file as mode 0600 owned by the broker', function () use ($roleTask) {
    $template = $roleTask('yak-broker', 'Write the broker environment')['ansible.builtin.template'];

    expect($template)->toMatchArray(['mode' => '0600', 'owner' => '{{ yak_broker_user }}', 'dest' => '/etc/yak/broker.env']);
});

it('names the broker environment variables the Go side reads', function () {
    $template = file_get_contents(base_path('ansible/roles/yak-broker/templates/broker.env.j2'));

    foreach (['YAK_BROKER_REDIS_ADDR', 'YAK_BROKER_REDIS_PASSWORD', 'YAK_BROKER_CREDENTIAL_SOCKET', 'YAK_BROKER_PROXY_ADDR', 'YAK_BROKER_PROXY_HOST_CIDRS'] as $name) {
        expect($template)->toContain("{$name}=");
    }
});

it('restarts the broker unit always, hardened, reading the env file', function () {
    $unit = file_get_contents(base_path('ansible/roles/yak-broker/templates/yak-broker.service.j2'));

    expect($unit)->toContain('Restart=always')
        ->toContain('NoNewPrivileges=true')
        ->toContain('EnvironmentFile=/etc/yak/broker.env')
        ->toContain('User={{ yak_broker_user }}')
        ->toMatch('/After=.*incus\.service/')
        ->toMatch('/After=.*docker\.service/');
});

it('restarts the broker on binary, env or unit change', function () use ($roleTask) {
    foreach (['Install the broker binary', 'Write the broker environment', 'Install the broker unit'] as $name) {
        expect((array) $roleTask('yak-broker', $name)['notify'])->toContain('Restart yak-broker');
    }
});

it('builds the broker binary on the controller unless CI supplied one', function () use ($roleTask) {
    $build = $roleTask('yak-broker', 'Build the broker on the controller');

    expect($build['when'])->toContain('not')
        ->and($build['delegate_to'])->toBe('localhost')
        ->and($build['run_once'])->toBeTrue()
        ->and(file_get_contents(base_path('.gitignore')))->toContain('broker/dist/');
});

it('lets sandboxes reach only the auth proxy port on the bridge', function () use ($roleTask) {
    $rule = $roleTask('incus', 'Allow the sandbox bridge to reach the auth proxy')['community.general.ufw'];

    expect($rule)->toMatchArray(['rule' => 'allow', 'direction' => 'in', 'interface' => 'yak-sandbox', 'proto' => 'tcp'])
        ->and($rule['to_port'])->toContain('yak_auth_proxy_address');
});

it('lets the Yak container reach the auth proxy', function () use ($roleTask) {
    $rule = $roleTask('incus', 'Allow the Yak container to reach the auth proxy')['community.general.ufw'];

    expect($rule['from_ip'])->toBe('{{ yak_auth_proxy_host_cidrs }}')
        ->and($rule['to_port'])->toContain('yak_auth_proxy_address');
});

$privateRanges = function (string $host): array {
    $result = Process::run(['python3', base_path('ansible/roles/incus/files/private_ranges.py'), $host]);
    expect($result->successful())->toBeTrue();

    return explode(',', trim($result->output()));
};

$cidrContains = function (string $cidr, string $ip): bool {
    [$base, $bits] = explode('/', $cidr);
    $mask = $bits === '0' ? 0 : (~0 << (32 - (int) $bits)) & 0xFFFFFFFF;

    return (ip2long($ip) & $mask) === (ip2long($base) & $mask);
};

it('private ranges exclude the bridge host address but cover the rest', function () use ($privateRanges, $cidrContains) {
    $cidrs = $privateRanges('10.100.0.1');

    foreach ($cidrs as $cidr) {
        expect($cidrContains($cidr, '10.100.0.1'))->toBeFalse();
    }
    foreach (['10.100.0.2', '10.0.0.1', '192.168.1.1', '100.64.0.1'] as $ip) {
        expect(collect($cidrs)->filter(fn ($cidr) => $cidrContains($cidr, $ip)))->toHaveCount(1);
    }
});

it('private ranges print a range whole when it does not contain the host', function () use ($privateRanges) {
    expect($privateRanges('10.100.0.1'))->toContain('192.168.0.0/16');
});

it('private ranges reject a bad address', function () {
    expect(Process::run(['python3', base_path('ansible/roles/incus/files/private_ranges.py'), 'nope'])->exitCode())->toBe(2);
});

$incusTasks = fn (): string => file_get_contents(base_path('ansible/roles/incus/tasks/main.yml'));

it('acl template has the spec rules in the spec order', function () {
    $template = file_get_contents(base_path('ansible/roles/incus/templates/yak-sandbox-acl.yaml.j2'));
    $positions = array_map(fn ($needle) => strpos($template, $needle), [
        'ingress:',
        'source: {{ yak_sandbox_bridge_host }}/32',
        'egress:',
        "destination_port: \"{{ yak_auth_proxy_address.split(':')[1] }}\"",
        'destination_port: "53,67"',
        'destination_port: "53"',
        'protocol: icmp4',
        'destination: {{ yak_host_public_cidrs }}',
        'destination: {{ yak_private_ranges }}',
    ]);

    expect($positions)->not->toContain(false)
        ->and($positions)->toBe(collect($positions)->sort()->values()->all());

    $lastRule = array_slice(explode('  - action:', $template), -1)[0];
    expect($lastRule)->toContain('allow')->not->toContain('destination')
        ->and(substr($template, 0, strpos($template, 'egress:')))->not->toContain('action: drop');
});

it('acl is created with stdin from /dev/null', function () use ($roleTask) {
    expect($roleTask('incus', 'Create sandbox network ACL')['ansible.builtin.shell'])->toContain('incus network acl create yak-sandbox-acl')->toContain('</dev/null');
});

it('acl is applied by incus network acl edit from the rendered file', function () use ($incusTasks) {
    expect($incusTasks())->toContain('incus network acl edit yak-sandbox-acl <')
        ->and($incusTasks())->toContain('yak-sandbox-acl.yaml.j2')
        ->and($incusTasks())->toContain('register: sandbox_acl_file');
});

it('profile sets the acl and the three security keys', function () use ($roleTask) {
    $command = $roleTask('incus', 'Apply the sandbox ACL to the yak-sandbox-acl and default profiles')['ansible.builtin.shell'];

    foreach (['security.acls=yak-sandbox-acl', 'security.acls.default.ingress.action=drop', 'security.acls.default.egress.action=allow', 'security.mac_filtering=true', 'security.ipv4_filtering=true', 'security.port_isolation=true', 'network=yak-sandbox'] as $key) {
        expect($command)->toContain($key);
    }
    expect($command)->toContain('default');
});

it('sandboxes restart only when the acl or profile changed', function () use ($roleTask) {
    $task = $roleTask('incus', 'Restart running sandboxes after an ACL or profile change');

    expect($task['when'])->toContain('sandbox_acl_file.changed')->toContain('sandbox_acl_create.changed')->toContain('sandbox_acl_profile.changed')
        ->and($task['ansible.builtin.shell'])->toContain('incus restart')->toContain('yak-base')->toContain('yak-tpl-');
});

it('inotify limits are raised', function () use ($roleTask) {
    $loop = json_encode($roleTask('incus', 'Increase inotify limits for container density')['loop']);

    expect($loop)->toContain('"8192"')->toContain('"1048576"');
});

it('incus is pinned to 7.x before it is installed', function () use ($roleTask) {
    $pin = $roleTask('incus', 'Pin Incus to the 7.x series')['ansible.builtin.copy'];
    $names = array_column(Yaml::parseFile(base_path('ansible/roles/incus/tasks/main.yml')), 'name');

    expect($pin['dest'])->toBe('/etc/apt/preferences.d/incus')
        ->and($pin['content'])->toContain('Pin: version 1:{{ incus_version_pin }}*')->toContain('Pin-Priority: 1001')
        ->and(array_search('Pin Incus to the 7.x series', $names))->toBeLessThan(array_search('Install Incus', $names));
});

it('base template starts docker.socket, gives yak the docker group and runs sshd', function () use ($incusTasks) {
    expect($incusTasks())->toContain('systemctl enable --now docker.socket')
        ->toContain('usermod -aG docker yak')
        ->toContain('openssh-server')
        ->toContain('systemctl enable ssh');
});

it('caddy routes the Reverb paths on the dashboard host', function () {
    $caddy = file_get_contents(base_path('ansible/roles/ssl/templates/Caddyfile.j2'));
    $dashboard = explode('*.{{ yak_domain }}', $caddy)[0];

    expect($dashboard)->toContain('@reverb path /app/* /apps/*')
        ->toContain('handle @reverb')
        ->toContain('reverse_proxy localhost:8080')
        ->toMatch('/handle @reverb \{.*?\}\s+handle \{/s')
        ->and($caddy)->toContain('forward_auth localhost:8080')->toContain('/_yak/auth');
});

it('nginx proxies the Reverb paths with websocket upgrade', function () {
    $nginx = file_get_contents(base_path('docker/nginx.conf'));

    foreach (['/app/', '/apps/'] as $path) {
        preg_match('/location ' . preg_quote($path, '/') . ' \{(.*?)\}/s', $nginx, $block);
        expect($block)->not->toBeEmpty();
        expect($block[1])->toContain('proxy_pass http://127.0.0.1:8080')
            ->toContain('proxy_http_version 1.1')
            ->toContain('proxy_set_header Upgrade $http_upgrade')
            ->toContain('proxy_set_header Connection "upgrade"')
            ->toMatch('/proxy_read_timeout (3600|[4-9]\d{3}|\d{5,})s;/');
        expect(strpos($nginx, "location $path {"))->toBeLessThan(strpos($nginx, 'location / {'));
    }
});

it('restarts only running sandboxes, and succeeds when none are running', function () use ($ansibleYaml) {
    $tasks = $ansibleYaml('ansible/roles/incus/tasks/main.yml');
    $script = collect($tasks)->firstWhere('name', 'Restart running sandboxes after an ACL or profile change')['ansible.builtin.shell'];

    $run = function (string $listing) use ($script): array {
        $bin = sys_get_temp_dir() . '/incus-stub-' . bin2hex(random_bytes(6));
        mkdir($bin);
        $log = $bin . '/restarted';
        file_put_contents($bin . '/incus', "#!/usr/bin/env bash\nif [ \"\$1\" = list ]; then printf '%s' " . escapeshellarg($listing) . '; else echo "$2" >> ' . escapeshellarg($log) . "; fi\n");
        chmod($bin . '/incus', 0755);
        $result = Process::env(['PATH' => $bin . ':' . getenv('PATH')])->run(['bash', '-c', $script]);

        return [$result->exitCode(), is_file($log) ? trim((string) file_get_contents($log)) : ''];
    };

    expect($run(''))->toBe([0, '']);
    expect($run("yak-base,RUNNING\nyak-tpl-acme,RUNNING\nthread-7,RUNNING\nthread-8,STOPPED\nthread-9,RUNNING\n"))
        ->toBe([0, "thread-7\nthread-9"]);
});
