<?php

namespace App\Services\Devices;

use App\Models\Device;
use App\Models\DeviceAuthorization;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * The device authorization flow: the CLI starts it, a signed-in user approves it, the CLI redeems the token once.
 */
class DeviceFlow
{
    public const EXPIRES_IN_SECONDS = 600;

    public const POLL_INTERVAL_SECONDS = 5;

    public function __construct(private DeviceTokens $deviceTokens) {}

    /**
     * @param  array{name: string, os: string, arch: string, cli_version?: string|null}  $reported
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
            'arch' => $reported['arch'],
            'cli_version' => $reported['cli_version'] ?? null,
            'requested_from_ip' => $ip,
            'poll_interval' => self::POLL_INTERVAL_SECONDS,
            'expires_at' => now()->addSeconds(self::EXPIRES_IN_SECONDS),
        ]);

        return ['device_code' => $deviceCode, 'authorization' => $authorization];
    }

    public function find(string $userCode): ?DeviceAuthorization
    {
        $normalized = strtoupper((string) preg_replace('/[\s-]+/', '', $userCode));

        if (strlen($normalized) !== 8) {
            return null;
        }

        return DeviceAuthorization::query()
            ->where('user_code', substr($normalized, 0, 4) . '-' . substr($normalized, 4))
            ->where('status', 'pending')
            ->where('expires_at', '>', now())
            ->first();
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
    public function poll(string $deviceCode): array
    {
        $outcome = DB::transaction(function () use ($deviceCode): array|DeviceFlowException {
            $authorization = DeviceAuthorization::query()
                ->lockForUpdate()
                ->where('device_code_hash', $this->deviceTokens->hash($deviceCode))
                ->first();

            if ($authorization === null || $authorization->status === 'redeemed' || $authorization->isExpired()) {
                return new DeviceFlowException('expired_token', 'This sign-in code expired. Run yak login again.');
            }

            if ($authorization->status === 'denied') {
                return new DeviceFlowException('access_denied', 'The sign-in was denied.');
            }

            $lastPolledAt = $authorization->last_polled_at;

            if ($lastPolledAt !== null && $lastPolledAt->diffInSeconds(now(), true) < $authorization->poll_interval) {
                $authorization->update(['last_polled_at' => now(), 'poll_interval' => $authorization->poll_interval + 5]);

                return new DeviceFlowException('slow_down', 'Polling too fast. Wait longer between requests.');
            }

            $authorization->update(['last_polled_at' => now()]);

            if ($authorization->status !== 'approved' || $authorization->encrypted_token === null || $authorization->device === null) {
                return new DeviceFlowException('authorization_pending', 'Waiting for approval in the browser.');
            }

            $token = Crypt::decryptString($authorization->encrypted_token);
            $authorization->update(['status' => 'redeemed', 'encrypted_token' => null]);

            return ['token' => $token, 'device' => $authorization->device];
        });

        if ($outcome instanceof DeviceFlowException) {
            throw $outcome;
        }

        return $outcome;
    }
}
