<?php

use App\Services\Threads\SandboxAddress;

it('reads the eth0 address and ignores the docker bridge', function () {
    $output = "4: eth0    inet 10.100.0.3/24 metric 1024 brd 10.100.0.255 scope global dynamic eth0\\       valid_lft 3541sec preferred_lft 3541sec\n";

    expect(SandboxAddress::fromIpAddr($output))->toBe('10.100.0.3');
});

it('fails loudly when eth0 has no IPv4 address', function () {
    SandboxAddress::fromIpAddr('');
})->throws(RuntimeException::class, 'no IPv4 address on eth0');

it('runs ip addr for eth0 only', function () {
    expect(SandboxAddress::command())->toBe('ip -4 -o addr show dev eth0');
});
