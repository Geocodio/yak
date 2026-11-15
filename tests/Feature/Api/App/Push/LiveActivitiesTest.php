<?php

use App\Enums\ThreadShareMode;
use App\Models\Device;
use App\Models\DeviceLiveActivity;
use App\Models\User;
use App\Services\Threads\ThreadSharing;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;

function liveActivitiesPath(): string
{
    return '/api/app/v1/live-activities';
}

/**
 * @return array{0: Device, 1: array<string, string>, 2: User}
 */
function liveActivityPhone(): array
{
    $user = User::factory()->create(['name' => 'Test person']);
    [$device, $headers] = phoneWithHeaders(['user_id' => $user->id]);

    return [$device, $headers, $user];
}

it('replaces the device\'s set', function () {
    [$device, $headers, $user] = liveActivityPhone();
    [$a, $b, $c] = [privateThreadOf($user), privateThreadOf($user), privateThreadOf($user)];
    $old = CarbonImmutable::parse('2026-11-16 08:00:00', 'UTC');
    DeviceLiveActivity::factory()->create(['device_id' => $device->id, 'thread_id' => $a->id, 'started_at' => $old]);
    DeviceLiveActivity::factory()->create(['device_id' => $device->id, 'thread_id' => $b->id, 'source' => 'server', 'started_at' => $old]);

    $this->putJson(liveActivitiesPath(), ['threads' => [$b->public_id, $c->public_id]], $headers)->assertNoContent();

    $rows = DeviceLiveActivity::query()->where('device_id', $device->id)->get()->keyBy('thread_id');
    expect($rows->keys()->sort()->values()->all())->toBe(collect([$b->id, $c->id])->sort()->values()->all())
        ->and($rows[$b->id]->started_at->equalTo($old))->toBeTrue()
        ->and($rows[$b->id]->source)->toBe('server')
        ->and($rows[$c->id]->source)->toBe('app');
});

it('changes nothing on a repeat', function () {
    [$device, $headers, $user] = liveActivityPhone();
    $thread = privateThreadOf($user);
    $body = ['threads' => [$thread->public_id]];

    $this->putJson(liveActivitiesPath(), $body, $headers)->assertNoContent();
    $row = DeviceLiveActivity::query()->sole();
    $this->travel(5)->minutes();
    $this->putJson(liveActivitiesPath(), $body, $headers)->assertNoContent();

    expect(DeviceLiveActivity::query()->count())->toBe(1)
        ->and($row->fresh()->updated_at->equalTo($row->updated_at))->toBeTrue();
});

it('clears the set with an empty list and leaves other phones alone', function () {
    [$device, $headers, $user] = liveActivityPhone();
    $thread = privateThreadOf($user);
    DeviceLiveActivity::factory()->create(['device_id' => $device->id, 'thread_id' => $thread->id]);
    $other = DeviceLiveActivity::factory()->create(['thread_id' => $thread->id]);

    $this->putJson(liveActivitiesPath(), ['threads' => []], $headers)->assertNoContent();

    expect(DeviceLiveActivity::query()->pluck('id')->all())->toBe([$other->id]);
});

it('drops hidden and unknown threads without a word', function () {
    [, $headers, $user] = liveActivityPhone();
    $visible = privateThreadOf($user);
    $hidden = privateThreadOf(User::factory()->create());

    $visibleStatus = $this->putJson(liveActivitiesPath(), ['threads' => [$visible->public_id]], $headers);
    DeviceLiveActivity::query()->delete();
    $hiddenStatus = $this->putJson(liveActivitiesPath(), ['threads' => [$hidden->public_id, 'zzzzzzzz']], $headers);

    expect($hiddenStatus->status())->toBe($visibleStatus->status())->and($hiddenStatus->getContent())->toBe($visibleStatus->getContent())
        ->and(DeviceLiveActivity::query()->count())->toBe(0);
});

it('lets a share recipient report a shared thread', function () {
    [, $headers, $recipient] = liveActivityPhone();
    $owner = User::factory()->create();
    $thread = privateThreadOf($owner);
    app(ThreadSharing::class)->apply($thread, $owner, ThreadShareMode::People, [$recipient->id]);

    $this->putJson(liveActivitiesPath(), ['threads' => [$thread->public_id]], $headers)->assertNoContent();

    expect(DeviceLiveActivity::query()->where('thread_id', $thread->id)->count())->toBe(1);
});

it('validates the list', function (array $body) {
    [, $headers] = liveActivityPhone();

    $this->putJson(liveActivitiesPath(), $body, $headers)->assertUnprocessable();
    expect(DeviceLiveActivity::query()->count())->toBe(0);
})->with([
    '11 ids' => fn () => [['threads' => array_map(fn ($n) => sprintf('abcdef%02d', $n), range(10, 20))]],
    'an id with i' => [['threads' => ['abcdefgi']]],
    'nine characters' => [['threads' => ['abcdefghj']]],
    'a duplicate' => [['threads' => ['abcdefgh', 'abcdefgh']]],
    'missing' => [[]],
    'not an array' => [['threads' => 'abcdefgh']],
]);

it('accepts an empty list and ignores unknown fields', function () {
    [, $headers] = liveActivityPhone();

    $this->putJson(liveActivitiesPath(), ['threads' => [], 'extra' => 1], $headers)->assertNoContent();
});

it('answers 503 when the device row lock is held', function () {
    [, $headers, $user] = liveActivityPhone();
    $thread = privateThreadOf($user);
    DeviceLiveActivity::creating(fn () => throw tap(new QueryException('mysql', 'select 1', [], new PDOException('Lock wait timeout exceeded')), fn ($exception) => $exception->errorInfo = [null, 1205, 'Lock wait timeout exceeded']));

    $this->putJson(liveActivitiesPath(), ['threads' => [$thread->public_id]], $headers)
        ->assertStatus(503)
        ->assertJsonPath('error', 'unavailable')
        ->assertJsonPath('message', 'Yak is busy with another request for this device. Try again in a few seconds.');
});

it('refuses without a token, with a revoked phone and with a computer token', function () {
    $body = ['threads' => []];

    $this->putJson(liveActivitiesPath(), $body, ['X-Yak-Protocol' => '1'])->assertUnauthorized()->assertJsonPath('error', 'unauthenticated');

    [$device, $headers] = phoneWithHeaders();
    $device->update(['revoked_at' => now()]);
    $this->putJson(liveActivitiesPath(), $body, $headers)->assertUnauthorized()->assertJsonPath('error', 'device_revoked');

    [, $computerHeaders] = phoneWithHeaders(['user_id' => $device->user_id], isPhone: false);
    $this->putJson(liveActivitiesPath(), $body, $computerHeaders)->assertUnauthorized()->assertJsonPath('error', 'invalid_token');
});
