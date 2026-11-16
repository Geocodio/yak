<?php

namespace Database\Factories;

use App\Models\DevicePairingCode;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<DevicePairingCode>
 */
class DevicePairingCodeFactory extends Factory
{
    protected $model = DevicePairingCode::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'code_hash' => hash('sha256', Str::random(43)),
            'expires_at' => now()->addSeconds(120),
        ];
    }
}
