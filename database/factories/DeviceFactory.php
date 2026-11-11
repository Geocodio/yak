<?php

namespace Database\Factories;

use App\Models\Device;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Device>
 */
class DeviceFactory extends Factory
{
    protected $model = Device::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => fake()->word() . '-laptop',
            'os' => 'darwin',
            'arch' => 'arm64',
            'cli_version' => '0.1.0',
            'token_hash' => hash('sha256', 'yakdev_' . Str::random(40)),
        ];
    }

    public function revoked(): static
    {
        return $this->state(fn () => ['revoked_at' => now()]);
    }

    public function stale(): static
    {
        return $this->state(fn () => ['last_seen_at' => now()->subDays(Device::STALE_AFTER_DAYS + 1)]);
    }

    public function forUser(User $user): static
    {
        return $this->state(fn () => ['user_id' => $user->id]);
    }
}
