<?php

namespace App\Services\Devices;

use App\Enums\DeviceKind;
use App\Models\Device;
use App\Models\DeviceAuthorization;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * The device authorization flow: a client (the CLI or a phone) starts it, a signed-in user approves it, the client redeems the token once.
 */
class DeviceFlow
{
    public const EXPIRES_IN_SECONDS = 600;

    public const POLL_INTERVAL_SECONDS = 5;

    /** How long after the redeem a repeated poll still gets the same token, so a lost response can be retried. */
    public const REPEAT_WINDOW_SECONDS = 60;

    public function __construct(private DeviceTokens $deviceTokens) {}

    /**
     * A computer reports `os` and `arch`; a phone reports its OS version as `os`, plus `model` and `app_version`.
     *
     * @param  array{name: string, os: string, arch?: string|null, cli_version?: string|null, kind?: DeviceKind, model?: string|null, app_version?: string|null}  $reported
     * @return array{device_code: string, authorization: DeviceAuthorization}
     */
    public function start(array $reported, ?string $ip): array
    {
        $deviceCode = $this->deviceTokens->generateCode();

        $authorization = DeviceAuthorization::query()->create([
            'device_code_hash' => $this->deviceTokens->hash($deviceCode),
            'user_code' => $this->deviceTokens->generateUserCode(),
            'name' => $reported['name'],
            'os' => $reported['os'],
            'arch' => $reported['arch'] ?? null,
            'cli_version' => $reported['cli_version'] ?? null,
            'kind' => $reported['kind'] ?? DeviceKind::Computer,
            'model' => $reported['model'] ?? null,
            'app_version' => $reported['app_version'] ?? null,
            'requested_from_ip' => $ip,
            'poll_interval' => self::POLL_INTERVAL_SECONDS,
            'expires_at' => now()->addSeconds(self::EXPIRES_IN_SECONDS),
        ]);

        return ['device_code' => $deviceCode, 'authorization' => $authorization];
    }

    public function find(#[\SensitiveParameter] string $userCode): ?DeviceAuthorization
    {
        $authorization = $this->findInAnyState($userCode);

        return $authorization !== null && $authorization->status === 'pending' && ! $authorization->isExpired() ? $authorization : null;
    }

    /**
     * Looks a typed code up by its normalised form whatever the row's state, so the page can tell expired from unknown.
     */
    public function findInAnyState(#[\SensitiveParameter] string $userCode): ?DeviceAuthorization
    {
        $normalized = strtoupper((string) preg_replace('/[\s-]+/', '', $userCode));

        if (strlen($normalized) !== 8) {
            return null;
        }

        return DeviceAuthorization::query()->where('user_code', substr($normalized, 0, 4) . '-' . substr($normalized, 4))->first();
    }

    public function approve(DeviceAuthorization $authorization, User $user, ?string $name): Device
    {
        return DB::transaction(function () use ($authorization, $user, $name): Device {
            $locked = DeviceAuthorization::query()->lockForUpdate()->findOrFail($authorization->id);

            if ($locked->status !== 'pending' || $locked->isExpired()) {
                throw new DeviceFlowException('expired_token', 'This sign-in code is no longer valid.');
            }

            $token = $this->deviceTokens->generate();
            $chosenName = trim((string) $name);

            $device = Device::query()->create([
                'user_id' => $user->id,
                'name' => $chosenName !== '' ? $chosenName : $locked->name,
                'os' => $locked->os,
                'arch' => $locked->arch,
                'cli_version' => $locked->cli_version,
                'kind' => $locked->kind,
                'model' => $locked->model,
                'app_version' => $locked->app_version,
                'token_hash' => $this->deviceTokens->hash($token),
            ]);

            $locked->update([
                'status' => 'approved',
                'user_id' => $user->id,
                'device_id' => $device->id,
                'encrypted_token' => Crypt::encryptString($token),
            ]);

            return $device;
        });
    }

    public function deny(DeviceAuthorization $authorization): void
    {
        DeviceAuthorization::query()->whereKey($authorization->id)->where('status', 'pending')->update(['status' => 'denied']);
    }

    /**
     * @return array{token: string, device: Device}
     *
     * @throws DeviceFlowException
     */
    public function poll(#[\SensitiveParameter] string $deviceCode, DeviceKind $kind = DeviceKind::Computer, #[\SensitiveParameter] ?string $requestId = null): array
    {
        $outcome = DB::transaction(function () use ($deviceCode, $kind, $requestId): array|DeviceFlowException {
            $authorization = DeviceAuthorization::query()
                ->lockForUpdate()
                ->where('device_code_hash', $this->deviceTokens->hash($deviceCode))
                ->where('kind', $kind->value)
                ->first();

            if ($authorization !== null && $authorization->status === 'redeemed') {
                return $this->repeat($authorization, $kind, $requestId);
            }

            if ($authorization === null || $authorization->isExpired()) {
                return new DeviceFlowException('expired_token', $kind === DeviceKind::Phone ? 'This sign-in code expired. Start pairing again in the app.' : 'This sign-in code expired. Run yak login again.');
            }

            if ($authorization->status === 'denied') {
                return new DeviceFlowException('access_denied', $kind === DeviceKind::Phone ? 'The sign-in was turned down in the browser. Start pairing again in the app.' : 'The sign-in was denied.');
            }

            if ($this->isPolledTooFast($authorization)) {
                return new DeviceFlowException('slow_down', 'Polling too fast. Wait longer between requests.');
            }

            $authorization->update(['last_polled_at' => now()]);

            if ($authorization->status !== 'approved' || $authorization->encrypted_token === null || $authorization->device === null) {
                return new DeviceFlowException('authorization_pending', 'Waiting for approval in the browser.');
            }

            $token = Crypt::decryptString($authorization->encrypted_token);
            $authorization->update([
                'status' => 'redeemed',
                'redeemed_at' => now(),
                'request_id_hash' => $requestId !== null ? $this->deviceTokens->hash($requestId) : null,
                'encrypted_token' => $requestId !== null ? $authorization->encrypted_token : null,
            ]);

            return ['token' => $token, 'device' => $authorization->device];
        });

        if ($outcome instanceof DeviceFlowException) {
            throw $outcome;
        }

        return $outcome;
    }

    /**
     * Records the poll; true (and a longer interval) when it came sooner than the interval allows.
     */
    private function isPolledTooFast(DeviceAuthorization $authorization): bool
    {
        $lastPolledAt = $authorization->last_polled_at;

        if ($lastPolledAt !== null && $lastPolledAt->diffInSeconds(now(), true) < $authorization->poll_interval) {
            $authorization->update(['last_polled_at' => now(), 'poll_interval' => $authorization->poll_interval + 5]);

            return true;
        }

        $authorization->update(['last_polled_at' => now()]);

        return false;
    }

    /**
     * The first poll's answer for a repeat that carries the same request id inside the window; otherwise the kept token is cleared once the window has passed and the code is gone.
     *
     * @return array{token: string, device: Device}|DeviceFlowException
     */
    private function repeat(DeviceAuthorization $authorization, DeviceKind $kind, #[\SensitiveParameter] ?string $requestId): array|DeviceFlowException
    {
        $device = $authorization->device;
        $isWithinWindow = $authorization->redeemed_at !== null && $authorization->redeemed_at->diffInSeconds(now(), true) < self::REPEAT_WINDOW_SECONDS;

        if (! $isWithinWindow && $authorization->encrypted_token !== null) {
            $authorization->update(['encrypted_token' => null, 'request_id_hash' => null]);
        }

        if (
            $isWithinWindow && $requestId !== null && $device !== null
            && $authorization->encrypted_token !== null && $authorization->request_id_hash !== null
            && hash_equals($authorization->request_id_hash, $this->deviceTokens->hash($requestId))
        ) {
            if ($this->isPolledTooFast($authorization)) {
                return new DeviceFlowException('slow_down', 'Polling too fast. Wait longer between requests.');
            }

            return ['token' => Crypt::decryptString($authorization->encrypted_token), 'device' => $device];
        }

        return new DeviceFlowException('expired_token', $kind === DeviceKind::Phone ? 'This sign-in code expired. Start pairing again in the app.' : 'This sign-in code expired. Run yak login again.');
    }
}
