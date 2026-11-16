<?php

namespace App\Services\Threads;

use RuntimeException;

/**
 * The sandbox address the auth proxy binds a placeholder to: the IPv4 address
 * of the sandbox's own network interface on the Incus bridge. `hostname -I` is
 * not used because Docker inside a sandbox adds its bridge (172.17.0.1) and may
 * list it first, and a placeholder bound to that address never matches.
 */
final class SandboxAddress
{
    /** The command whose output {@see fromIpAddr()} parses. */
    public const COMMAND = ['ip', '-4', '-o', 'addr', 'show', 'dev', 'eth0'];

    public static function command(): string
    {
        return implode(' ', self::COMMAND);
    }

    /**
     * Reads the address from `ip -4 -o addr show dev eth0` output, for example
     * "2: eth0    inet 10.100.0.3/24 brd 10.100.0.255 scope global eth0".
     */
    public static function fromIpAddr(string $output): string
    {
        if (preg_match('/\binet\s+(\d{1,3}(?:\.\d{1,3}){3})\//', $output, $matches) !== 1) {
            throw new RuntimeException('The sandbox has no IPv4 address on eth0');
        }

        return $matches[1];
    }
}
