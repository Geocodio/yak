<?php

namespace App\Services\Devices;

use App\Enums\DeviceKind;
use App\Models\Device;
use App\Models\DevicePairingCode;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * One-time pairing codes shown as a QR code on the web and redeemed by a phone for a device token.
 */
class PairingCodes
{
    public function __construct(private DeviceTokens $deviceTokens) {}

    /**
     * @return array{pairingCode: DevicePairingCode, code: string}
     */
    public function create(User $user): array
    {
        $code = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

        $pairingCode = DevicePairingCode::query()->create([
            'user_id' => $user->id,
            'code_hash' => $this->deviceTokens->hash($code),
            'expires_at' => now()->addSeconds((int) config('yak.pairing.code_ttl_seconds')),
        ]);

        return ['pairingCode' => $pairingCode, 'code' => $code];
    }

    /**
     * Redeems a code once. The row lock and the conditional write both make two phones that scan the same code at the same moment race: the loser sees it redeemed and its device is rolled back.
     *
     * The first redeem keeps the issued token, encrypted, until the code expires. A repeat with the same `$requestId` before then gets the same device and token back, so a lost response can be retried. Any other repeat is refused.
     *
     * @param  array{name: string, os: string, model: string, app_version: string}  $reported
     * @return array{token: string, device: Device}
     *
     * @throws DeviceFlowException
     */
    public function redeem(#[\SensitiveParameter] string $code, array $reported, #[\SensitiveParameter] ?string $requestId = null): array
    {
        $invalid = new DeviceFlowException('pairing_code_invalid', 'This pairing code is not valid. Show a new QR code on the Devices page and scan it again.');

        $outcome = DB::transaction(function () use ($code, $reported, $requestId, $invalid): array|DeviceFlowException {
            $pairingCode = DevicePairingCode::query()
                ->lockForUpdate()
                ->where('code_hash', $this->deviceTokens->hash($code))
                ->first();

            if ($pairingCode === null || ! User::query()->whereKey($pairingCode->user_id)->exists()) {
                return $invalid;
            }

            if ($pairingCode->redeemed_at !== null) {
                return $this->repeat($pairingCode, $requestId) ?? $invalid;
            }

            if ($pairingCode->isExpired()) {
                return new DeviceFlowException('pairing_code_expired', 'This pairing code expired. Show a new QR code on the Devices page and scan it again.');
            }

            $token = $this->deviceTokens->generate();

            $device = Device::query()->create([
                'user_id' => $pairingCode->user_id,
                'name' => $reported['name'],
                'os' => $reported['os'],
                'kind' => DeviceKind::Phone,
                'model' => $reported['model'],
                'app_version' => $reported['app_version'],
                'token_hash' => $this->deviceTokens->hash($token),
            ]);

            $claimed = DevicePairingCode::query()->whereKey($pairingCode->id)->whereNull('redeemed_at')->update([
                'redeemed_at' => now(),
                'device_id' => $device->id,
                'request_id_hash' => $requestId !== null ? $this->deviceTokens->hash($requestId) : null,
                'encrypted_token' => $requestId !== null ? Crypt::encryptString($token) : null,
            ]);

            if ($claimed !== 1) {
                throw $invalid;
            }

            return ['token' => $token, 'device' => $device];
        });

        if ($outcome instanceof DeviceFlowException) {
            throw $outcome;
        }

        return $outcome;
    }

    /**
     * The first redeem's answer for a repeat that carries the same request id inside the code's lifetime; a repeat after that only clears the kept token.
     *
     * @return array{token: string, device: Device}|null
     */
    private function repeat(DevicePairingCode $pairingCode, #[\SensitiveParameter] ?string $requestId): ?array
    {
        $device = $pairingCode->device;
        $isWithinWindow = ! $pairingCode->isExpired();

        if (! $isWithinWindow && $pairingCode->encrypted_token !== null) {
            $pairingCode->forceFill(['encrypted_token' => null, 'request_id_hash' => null])->save();
        }

        if (
            ! $isWithinWindow || $requestId === null || $device === null
            || $pairingCode->encrypted_token === null || $pairingCode->request_id_hash === null
            || ! hash_equals($pairingCode->request_id_hash, $this->deviceTokens->hash($requestId))
        ) {
            return null;
        }

        return ['token' => Crypt::decryptString($pairingCode->encrypted_token), 'device' => $device];
    }

    public function qrPayload(#[\SensitiveParameter] string $code): string
    {
        return 'yak://pair?url=' . urlencode((string) config('app.url')) . '&code=' . $code;
    }
}
