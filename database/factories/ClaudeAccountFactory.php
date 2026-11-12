<?php

namespace Database\Factories;

use App\Enums\ClaudeAccountColor;
use App\Enums\ClaudeAccountIcon;
use App\Enums\ClaudeAccountKind;
use App\Enums\ClaudeAccountStatus;
use App\Models\ClaudeAccount;
use App\Models\User;
use Carbon\CarbonImmutable;
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
            'name' => fake()->unique()->words(2, true),
            'kind' => ClaudeAccountKind::SetupToken,
            'secret' => 'sk-ant-oat01-' . fake()->regexify('[A-Za-z0-9]{40}'),
            'icon' => ClaudeAccountIcon::Briefcase,
            'color' => ClaudeAccountColor::Violet,
            'default_model' => 'sonnet',
            'max_concurrent_turns' => 16,
            'is_default' => false,
            'status' => ClaudeAccountStatus::Active,
        ];
    }

    public function system(): static
    {
        return $this->state(['owner_user_id' => null]);
    }

    public function apiKey(): static
    {
        return $this->state([
            'kind' => ClaudeAccountKind::ApiKey,
            'secret' => 'sk-ant-api03-' . fake()->regexify('[A-Za-z0-9]{40}'),
        ]);
    }

    public function invalid(): static
    {
        return $this->state(['status' => ClaudeAccountStatus::Invalid, 'last_error' => 'Authentication failed']);
    }

    public function expiring(int $days = 10): static
    {
        return $this->state(['expires_at' => now()->addDays($days)]);
    }

    public function expired(): static
    {
        return $this->state(['status' => ClaudeAccountStatus::Expired, 'expires_at' => now()->subDay()]);
    }

    public function usageLimited(?CarbonImmutable $resetsAt = null): static
    {
        return $this->state(['usage_limit_resets_at' => $resetsAt ?? now()->addHour()]);
    }

    public function default(): static
    {
        return $this->state(['is_default' => true]);
    }
}
