<?php

namespace Database\Factories;

use App\Enums\SshKeySource;
use App\Models\SshKey;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SshKey>
 */
class SshKeyFactory extends Factory
{
    protected $model = SshKey::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $blob = pack('N', 11) . 'ssh-ed25519' . pack('N', 32) . random_bytes(32);

        return [
            'user_id' => User::factory(),
            'source' => SshKeySource::Uploaded,
            'name' => fake()->word() . ' laptop',
            'type' => 'ssh-ed25519',
            'public_key' => 'ssh-ed25519 ' . base64_encode($blob),
            'fingerprint' => 'SHA256:' . rtrim(base64_encode(hash('sha256', $blob, true)), '='),
        ];
    }

    public function fromGithub(): static
    {
        return $this->state(fn () => [
            'source' => SshKeySource::Github,
            'github_added_at' => now()->subYear(),
        ]);
    }

    public function forUser(User $user): static
    {
        return $this->state(fn () => ['user_id' => $user->id]);
    }
}
