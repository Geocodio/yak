<?php

namespace Database\Factories;

use App\Enums\ClaudeAccountKind;
use App\Enums\ClaudeAccountStatus;
use App\Models\ClaudeAccount;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ClaudeAccount>
 */
class ClaudeAccountFactory extends Factory
{
    protected $model = ClaudeAccount::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'owner_user_id' => User::factory(),
            'name' => fake()->words(2, true),
            'kind' => ClaudeAccountKind::SetupToken,
            'secret' => 'sk-ant-oat01-' . fake()->sha256(),
            'status' => ClaudeAccountStatus::Active,
            'is_default' => false,
            'validated_at' => now(),
            'expires_at' => now()->addYear(),
        ];
    }

    public function system(): static
    {
        return $this->state(fn (): array => ['owner_user_id' => null]);
    }

    public function apiKey(): static
    {
        return $this->state(fn (): array => [
            'kind' => ClaudeAccountKind::ApiKey,
            'secret' => 'sk-ant-api03-' . fake()->sha256(),
            'expires_at' => null,
        ]);
    }

    public function invalid(): static
    {
        return $this->state(fn (): array => [
            'status' => ClaudeAccountStatus::Invalid,
            'last_error' => 'Authentication failed',
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn (): array => ['expires_at' => now()->subDay()]);
    }
}
