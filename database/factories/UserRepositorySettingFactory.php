<?php

namespace Database\Factories;

use App\Models\ClaudeAccount;
use App\Models\User;
use App\Models\UserRepositorySetting;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UserRepositorySetting>
 */
class UserRepositorySettingFactory extends Factory
{
    protected $model = UserRepositorySetting::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'repo' => 'acme/widgets',
            'claude_account_id' => ClaudeAccount::factory(),
            'model' => null,
        ];
    }
}
