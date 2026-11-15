<?php

namespace Database\Factories;

use App\Enums\PushTokenKind;
use App\Models\Device;
use App\Models\DevicePushToken;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DevicePushToken>
 */
class DevicePushTokenFactory extends Factory
{
    protected $model = DevicePushToken::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'device_id' => Device::factory()->phone(),
            'kind' => PushTokenKind::Alert,
            'token' => str_repeat('a1', 32),
            'environment' => 'production',
        ];
    }
}
