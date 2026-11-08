<?php

namespace App\Services\Threads;

use Closure;
use RuntimeException;
use Socket;

/**
 * Serves the credential behind an auth proxy placeholder on a Unix socket. The socket file is reachable
 * only by the broker's group, and every connection is also checked against the
 * broker's uid with the kernel's peer credentials (SCM_CREDENTIALS), which the
 * peer cannot forge. One request line `{"reference": "..."}` gets one reply line:
 * the credential, or `{"error": "..."}`.
 */
class CredentialSocketServer
{
    private ?Socket $listener = null;

    private int $allowedUid = -1;

    /**
     * @param  (Closure(Socket): ?int)|null  $peerUidOverride  replaces the kernel's answer; tests only
     */
    public function __construct(private readonly AuthProxyPlaceholders $placeholders, private readonly ?Closure $peerUidOverride = null) {}

    public function listen(string $path, int $allowedUid, int $mode = 0660): void
    {
        if (file_exists($path)) {
            unlink($path);
        }

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

        chmod($path, $mode);
        socket_set_nonblock($listener);
        $this->listener = $listener;
        $this->allowedUid = $allowedUid;
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
        $credential = is_string($placeholder) ? $this->placeholders->resolve($placeholder) : null;

        $this->reply($connection, $credential ?? ['error' => 'unknown or revoked placeholder']);
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
