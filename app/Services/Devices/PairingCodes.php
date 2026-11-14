<?php

namespace App\Services\Devices;

use App\Enums\DeviceKind;
use App\Models\Device;
use App\Models\DevicePairingCode;
use App\Models\User;
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
     * @param  array{name: string, os: string, model: string, app_version: string}  $reported
     * @return array{token: string, device: Device}
     *
     * @throws DeviceFlowException
     */
    public function redeem(string $code, array $reported): array
    {
        return DB::transaction(function () use ($code, $reported): array {
            $pairingCode = DevicePairingCode::query()
                ->lockForUpdate()
                ->where('code_hash', $this->deviceTokens->hash($code))
                ->first();

            if ($pairingCode === null || $pairingCode->redeemed_at !== null || ! User::query()->whereKey($pairingCode->user_id)->exists()) {
                throw new DeviceFlowException('pairing_code_invalid', 'This pairing code is not valid. Show a new QR code on the Devices page and scan it again.');
            }

            if ($pairingCode->isExpired()) {
                throw new DeviceFlowException('pairing_code_expired', 'This pairing code expired. Show a new QR code on the Devices page and scan it again.');
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

            $claimed = DevicePairingCode::query()->whereKey($pairingCode->id)->whereNull('redeemed_at')
                ->update(['redeemed_at' => now(), 'device_id' => $device->id]);

            if ($claimed !== 1) {
                throw new DeviceFlowException('pairing_code_invalid', 'This pairing code is not valid. Show a new QR code on the Devices page and scan it again.');
            }

            return ['token' => $token, 'device' => $device];
        });
    }

    public function qrPayload(string $code): string
    {
        return 'yak://pair?url=' . urlencode((string) config('app.url')) . '&code=' . $code;
    }
}
