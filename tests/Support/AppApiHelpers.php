<?php

use App\Models\CoOwnerRequest;
use App\Models\Device;
use App\Models\Thread;
use App\Models\ThreadStar;
use App\Models\User;
use App\Models\YakTask;
use App\Services\Devices\DeviceTokens;

/**
 * @param  array<string, mixed>  $attributes
 * @return array{0: Device, 1: array<string, string>}
 */
function phoneWithHeaders(array $attributes = [], bool $isPhone = true): array
{
    $token = app(DeviceTokens::class)->generate();
    $attributes['user_id'] ??= User::factory()->create(['name' => 'Test Person', 'email' => 'person@example.test'])->id;
    $attributes += ['name' => 'Test phone'];
    $factory = $isPhone ? Device::factory()->phone() : Device::factory();
    $device = $factory->create(['token_hash' => app(DeviceTokens::class)->hash($token)] + $attributes);

    return [$device, ['X-Yak-Protocol' => '1', 'Authorization' => "Bearer {$token}"]];
}

/**
 * A new phone device for the user, as request headers.
 *
 * @return array<string, string>
 */
function phoneHeadersFor(User $user): array
{
    return phoneWithHeaders(['user_id' => $user->id])[1];
}

/**
 * @param  array<string, mixed>  $attributes
 */
function privateThreadOf(User $owner, array $attributes = []): Thread
{
    return Thread::factory()->create($attributes + [
        'visibility' => 'private',
        'owner_user_id' => $owner->id,
        'created_by_user_id' => $owner->id,
    ]);
}

function appThreadPath(Thread $thread, string $suffix = ''): string
{
    return "/api/app/v1/threads/{$thread->public_id}{$suffix}";
}

function needyTask(User $user, string $description, ?Thread $thread = null): YakTask
{
    return YakTask::factory()->awaitingClarification()->create([
        'description' => $description,
        'started_by_user_id' => $user->id,
        'thread_id' => $thread?->id,
    ]);
}

/**
 * @return array{user: User, other: User}
 */
function needsYouWorld(): array
{
    $user = User::factory()->create(['name' => 'Test person']);
    $other = User::factory()->create();

    $starred = Thread::factory()->create(['created_by_user_id' => $user->id, 'title' => 'Starred thread']);
    needyTask($user, 'Starred head', $starred);
    ThreadStar::query()->create(['user_id' => $user->id, 'thread_id' => $starred->id]);

    $root = Thread::factory()->create(['created_by_user_id' => $user->id, 'title' => 'Root thread']);
    $child = Thread::factory()->child($root)->create(['created_by_user_id' => $user->id]);
    needyTask($user, 'Sub-thread head', $child);

    $coOwnerTask = YakTask::factory()->awaitingCoOwner()->create(['description' => 'Rotate the signing key']);
    CoOwnerRequest::factory()->create(['task_id' => $coOwnerTask->id, 'asked_user_ids' => [$user->id]]);
    CoOwnerRequest::factory()->create(['asked_user_ids' => [$other->id]]);

    needyTask($user, 'Pick a retry policy');

    $open = Thread::factory()->create(['created_by_user_id' => $user->id, 'title' => 'Open thread']);
    needyTask($user, 'Choose a batch size', $open);

    $hidden = Thread::factory()->private($other)->create();
    needyTask($user, 'Hidden question', $hidden);

    return ['user' => $user, 'other' => $other];
}
