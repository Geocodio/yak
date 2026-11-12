<?php

namespace App\Services\Threads;

use Closure;
use RuntimeException;
use Socket;

/**
 * Serves the credential behind an auth proxy placeholder on a Unix socket. The socket lives in a directory that
 * root owns (mode 2770, setgid, the broker's group), so it is created with umask 0117 as 0660 in the broker's
 * group and is never chowned or chmodded by path. Every connection is also checked against the broker's uid with
 * the kernel's peer credentials (SCM_CREDENTIALS), which the peer cannot forge. One request line
 * `{"placeholder": "...", "container_ip": "..."}` gets one reply line:
 * `{"kind", "secret", "account_id"}`, `{"error": "unknown"}` or `{"error": "forbidden"}`. Never logs.
 */
class CredentialSocketServer
{
    public const EXPECTED_DIRECTORY_MODE = 02770;

    private ?Socket $listener = null;

    private int $allowedUid = -1;

    /**
     * @param  (Closure(Socket): ?int)|null  $peerUidOverride  replaces the kernel's answer; tests only
     */
    public function __construct(private readonly AuthProxyPlaceholders $placeholders, private readonly ?Closure $peerUidOverride = null) {}

    public function listen(string $path, int $allowedUid, int $directoryOwnerUid = 0, ?int $directoryGid = null): void
    {
        $directory = dirname($path);
        $this->assertSafeDirectory($directory, $directoryOwnerUid, $directoryGid);
        $this->removeStaleSocket($path);

        $listener = socket_create(AF_UNIX, SOCK_STREAM, 0) ?: throw new RuntimeException('Cannot create the credential socket.');
        $previousMask = umask(0117);

        try {
            if (! socket_bind($listener, $path) || ! socket_listen($listener, 16)) {
                throw new RuntimeException('Cannot listen on ' . $path . ': ' . socket_strerror(socket_last_error($listener)));
            }
        } finally {
            umask($previousMask);
        }

        // Set before any peer connects, so the kernel attaches the peer's credentials to its first message.
        if (defined('SO_PASSCRED')) {
            socket_set_option($listener, SOL_SOCKET, SO_PASSCRED, 1);
        }

        $socketStat = @lstat($path);
        $directoryStat = @lstat($directory);

        if ($socketStat === false || $directoryStat === false || ($socketStat['mode'] & 0777) !== 0660 || $socketStat['gid'] !== $directoryStat['gid']) {
            socket_close($listener);
            throw new RuntimeException('The credential socket ' . $path . ' is not 0660 in the directory group.');
        }

        socket_set_nonblock($listener);
        $this->listener = $listener;
        $this->allowedUid = $allowedUid;
    }

    private function assertSafeDirectory(string $directory, int $ownerUid, ?int $gid): void
    {
        $stat = @lstat($directory);
        $problem = match (true) {
            $stat === false => 'it does not exist',
            ($stat['mode'] & 0170000) !== 0040000 => 'it is not a real directory',
            $stat['uid'] !== $ownerUid => 'it is owned by uid ' . $stat['uid'] . ', not ' . $ownerUid,
            $gid !== null && $stat['gid'] !== $gid => 'it is owned by gid ' . $stat['gid'] . ', not ' . $gid,
            ($stat['mode'] & 07777 & ~self::EXPECTED_DIRECTORY_MODE) !== 0 => 'its mode is looser than ' . decoct(self::EXPECTED_DIRECTORY_MODE),
            ($stat['mode'] & 02000) === 0 => 'it lacks the setgid bit',
            default => null,
        };

        if ($problem !== null) {
            throw new RuntimeException('The credential socket directory ' . $directory . ' is not safe: ' . $problem);
        }
    }

    private function removeStaleSocket(string $path): void
    {
        $stat = @lstat($path);

        if ($stat === false) {
            return;
        }

        if (($stat['mode'] & 0170000) !== 0140000 || $stat['uid'] !== posix_geteuid()) {
            throw new RuntimeException('Refusing to remove ' . $path . ': it is not a socket owned by this process.');
        }

        unlink($path);
    }

    /**
     * Handles at most one waiting connection, waiting up to the given time for it.
     */
    public function serve(int $waitMicroseconds): void
    {
        if ($this->listener === null) {
            return;
        }

        $read = [$this->listener];
        $write = $except = [];

        if (@socket_select($read, $write, $except, 0, $waitMicroseconds) < 1) {
            return;
        }

        $connection = @socket_accept($this->listener);

        if ($connection === false) {
            return;
        }

        try {
            $this->respond($connection);
        } finally {
            socket_close($connection);
        }
    }

    public function close(): void
    {
        if ($this->listener !== null) {
            socket_close($this->listener);
            $this->listener = null;
        }
    }

    private function respond(Socket $connection): void
    {
        socket_set_option($connection, SOL_SOCKET, SO_RCVTIMEO, ['sec' => 2, 'usec' => 0]);

        if (defined('SO_PASSCRED')) {
            socket_set_option($connection, SOL_SOCKET, SO_PASSCRED, 1);
        }

        [$request, $kernelUid] = $this->receive($connection);
        $uid = $this->peerUidOverride !== null ? ($this->peerUidOverride)($connection) : $kernelUid;

        if ($uid !== $this->allowedUid) {
            $this->reply($connection, ['error' => 'forbidden']);

            return;
        }

        $decoded = json_decode(trim($request), true);
        $placeholder = is_array($decoded) ? ($decoded['placeholder'] ?? null) : null;
        $containerIp = is_array($decoded) ? ($decoded['container_ip'] ?? null) : null;
        $account = is_string($placeholder) && is_string($containerIp) ? $this->placeholders->resolve($placeholder, $containerIp) : null;

        $this->reply($connection, $account === null ? ['error' => 'unknown'] : [
            'kind' => $account->kind->value,
            'secret' => $account->secret,
            'account_id' => $account->id,
        ]);
    }

    /**
     * @return array{0: string, 1: ?int} the request and the uid the kernel saw on the peer
     */
    private function receive(Socket $connection): array
    {
        // Without SCM_CREDENTIALS (not Linux) the kernel reports no uid, so only an override can admit a peer.
        if (! defined('SCM_CREDENTIALS')) {
            return [(string) @socket_read($connection, 4096), null];
        }

        $message = ['name' => [], 'buffer_size' => 4096, 'controllen' => socket_cmsg_space(SOL_SOCKET, SCM_CREDENTIALS)];

        if (@socket_recvmsg($connection, $message) === false) {
            return ['', null];
        }

        $uid = null;

        foreach ($message['control'] ?? [] as $control) {
            if ($control['level'] === SOL_SOCKET && $control['type'] === SCM_CREDENTIALS) {
                $uid = (int) $control['data']['uid'];
            }
        }

        return [(string) ($message['iov'][0] ?? ''), $uid];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function reply(Socket $connection, array $payload): void
    {
        $line = json_encode($payload, JSON_THROW_ON_ERROR) . "\n";
        @socket_write($connection, $line, strlen($line));
    }
}
