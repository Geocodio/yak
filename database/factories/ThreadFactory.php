<?php

namespace Database\Factories;

use App\Enums\RunnerState;
use App\Enums\ThreadKind;
use App\Enums\ThreadOwnerSide;
use App\Enums\ThreadSandboxState;
use App\Enums\ThreadStatus;
use App\Enums\ThreadVisibility;
use App\Models\Device;
use App\Models\Thread;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Thread>
 */
class ThreadFactory extends Factory
{
    protected $model = Thread::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->slug(3),
            'title' => fake()->sentence(4),
            'repo' => 'acme/widgets',
            'status' => ThreadStatus::Open,
            'visibility' => ThreadVisibility::Team,
            'kind' => ThreadKind::General,
            'model' => 'sonnet',
        ];
    }

    /**
     * A private thread always has an owner.
     */
    public function private(?User $owner = null): static
    {
        return $this->state(fn (): array => [
            'visibility' => ThreadVisibility::Private,
            'owner_user_id' => $owner->id ?? User::factory()->create()->id,
        ]);
    }

    public function closed(): static
    {
        return $this->state([
            'status' => ThreadStatus::Closed,
            'closed_at' => now(),
            'close_reason' => 'merged',
        ]);
    }

    /**
     * A sub-thread of `$parent` (a new root thread when omitted). It copies
     * the root's visibility and owner, as the spawn code does.
     */
    public function child(?Thread $parent = null): static
    {
        return $this->state(function () use ($parent): array {
            $parent ??= Thread::factory()->create();
            $root = $parent->rootThread();

            return [
                'repo' => $parent->repo,
                'kind' => ThreadKind::Implement,
                'parent_thread_id' => $parent->id,
                'root_thread_id' => $root->id,
                'visibility' => $root->visibility,
                'owner_user_id' => $root->owner_user_id,
            ];
        });
    }

    public function ownedByDevice(?int $deviceId = null): static
    {
        return $this->state(fn (): array => [
            'owner_side' => ThreadOwnerSide::Device,
            'owner_device_id' => $deviceId ?? Device::factory(),
        ]);
    }

    public function paused(): static
    {
        return $this->state(fn (): array => [
            'sandbox_container' => 'yak-thread-' . fake()->unique()->numberBetween(1, 99999),
            'sandbox_state' => ThreadSandboxState::Paused,
            'sandbox_state_changed_at' => now(),
            'runner_state' => RunnerState::Idle,
            'runner_generation' => 1,
        ]);
    }

    public function stopped(): static
    {
        return $this->state(fn (): array => [
            'sandbox_container' => 'yak-thread-' . fake()->unique()->numberBetween(1, 99999),
            'sandbox_state' => ThreadSandboxState::Stopped,
            'sandbox_state_changed_at' => now(),
            'runner_state' => RunnerState::None,
        ]);
    }

    public function destroyed(): static
    {
        return $this->state([
            'sandbox_container' => null,
            'sandbox_state' => ThreadSandboxState::Destroyed,
            'sandbox_state_changed_at' => now(),
            'runner_state' => RunnerState::None,
        ]);
    }
}
