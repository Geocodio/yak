<?php

namespace App\Services\Devices;

use App\Models\Device;
use Illuminate\Support\Str;

/**
 * Generates and resolves device tokens. Only the SHA-256 hash of a token is stored.
 */
class DeviceTokens
{
    public const TOKEN_PREFIX = 'yakdev_';

    public const USER_CODE_ALPHABET = 'BCDFGHJKLMNPQRSTVWXZ23456789';

    public function generate(): string
    {
        return self::TOKEN_PREFIX . Str::random(40);
    }

    public function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * Returns revoked devices too; the caller decides what a revoked device may do.
     */
    public function resolve(?string $bearerToken): ?Device
    {
        if ($bearerToken === null || ! str_starts_with($bearerToken, self::TOKEN_PREFIX)) {
            return null;
        }

        return Device::query()->where('token_hash', $this->hash($bearerToken))->first();
    }

    public function generateCode(): string
    {
        return Str::random(40);
    }

    public function generateUserCode(): string
    {
        $characters = '';
        $alphabetLength = strlen(self::USER_CODE_ALPHABET);

        for ($index = 0; $index < 8; $index++) {
            $characters .= self::USER_CODE_ALPHABET[random_int(0, $alphabetLength - 1)];
        }

        return substr($characters, 0, 4) . '-' . substr($characters, 4);
    }
}
