<?php

namespace Database\Factories;

use App\Enums\DeviceKind;
use App\Models\DeviceAuthorization;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<DeviceAuthorization>
 */
class DeviceAuthorizationFactory extends Factory
{
    protected $model = DeviceAuthorization::class;

    public function phone(): static
    {
        return $this->state(fn () => [
            'kind' => DeviceKind::Phone,
            'model' => 'iPhone19,4',
            'os' => '27.2.1',
            'arch' => null,
            'cli_version' => null,
            'app_version' => '1.0.0',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'device_code_hash' => hash('sha256', Str::random(40)),
            'user_code' => Str::upper(Str::random(4)) . '-' . Str::upper(Str::random(4)),
            'name' => fake()->word() . '-laptop',
            'os' => 'darwin',
            'arch' => 'arm64',
            'cli_version' => '0.1.0',
            'requested_from_ip' => '203.0.113.7',
            'status' => 'pending',
            'poll_interval' => 5,
            'expires_at' => now()->addSeconds(600),
        ];
    }
}
