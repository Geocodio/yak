<?php

namespace Database\Factories;

use App\Models\PushDevice;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Minishlink\WebPush\ContentEncoding;

/**
 * @extends Factory<PushDevice>
 */
class PushDeviceFactory extends Factory
{
    protected $model = PushDevice::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'subscribable_type' => (new User)->getMorphClass(),
            'subscribable_id' => User::factory(),
            'endpoint' => 'https://push.example.test/send/' . Str::random(32),
            'public_key' => Str::random(87),
            'auth_token' => Str::random(22),
            'content_encoding' => ContentEncoding::aes128gcm,
            'label' => 'Chrome on macOS',
            'user_agent' => 'Mozilla/5.0 (Macintosh)',
            'is_enabled' => true,
        ];
    }

    public function forUser(User $user): static
    {
        return $this->state(['subscribable_type' => $user->getMorphClass(), 'subscribable_id' => $user->id]);
    }

    public function disabled(): static
    {
        return $this->state(['is_enabled' => false]);
    }

    public function expired(): static
    {
        return $this->state(['last_error' => 'Subscription expired (410)']);
    }
}
