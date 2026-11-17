<?php

use App\Enums\ThreadOwnerSide;
use App\Enums\ThreadSource;
use App\Http\Resources\ThreadOriginData;
use App\Models\Thread;
use App\Models\User;
use App\Models\YakTask;
use App\Services\Threads\BackfillThreadOrigin;

it('filters by sources with OR and ignores unknown keys', function () {
    $slack = Thread::factory()->create(['source' => 'slack']);
    $linear = Thread::factory()->create(['source' => 'linear']);
    $sentry = Thread::factory()->create(['source' => 'sentry']);

    expect(Thread::query()->whereSources(['slack', 'linear', 'bogus'])->pluck('id')->all())
        ->toEqualCanonicalizing([$slack->id, $linear->id])
        ->and(Thread::query()->whereSources(['bogus'])->count())->toBe(3)
        ->and(Thread::query()->whereSources([])->count())->toBe(3);
    expect($sentry->id)->not->toBeNull();
});

it('filters by starters across user, name and yak', function () {
    $user = User::factory()->create();
    $byUser = Thread::factory()->create(['created_by_user_id' => $user->id]);
    $byName = Thread::factory()->create(['created_by_user_id' => null, 'starter_name' => 'Ada']);
    $byYak = Thread::factory()->create(['created_by_user_id' => null, 'starter_name' => null]);

    expect(Thread::query()->whereStarters(['user:' . $user->id])->pluck('id')->all())->toBe([$byUser->id])
        ->and(Thread::query()->whereStarters(['name:Ada'])->pluck('id')->all())->toBe([$byName->id])
        ->and(Thread::query()->whereStarters(['yak'])->pluck('id')->all())->toBe([$byYak->id])
        ->and(Thread::query()->whereStarters(['name:Ada', 'yak'])->pluck('id')->all())->toEqualCanonicalizing([$byName->id, $byYak->id])
        ->and(Thread::query()->whereStarters([])->count())->toBe(3);
});

it('combines sources and starters as AND', function () {
    $user = User::factory()->create();
    $match = Thread::factory()->create(['source' => 'slack', 'created_by_user_id' => $user->id]);
    Thread::factory()->create(['source' => 'linear', 'created_by_user_id' => $user->id]);
    Thread::factory()->create(['source' => 'slack', 'created_by_user_id' => null]);

    expect(Thread::query()->whereSources(['slack'])->whereStarters(['user:' . $user->id])->pluck('id')->all())->toBe([$match->id]);
});

it('hides repository research from listed', function () {
    $visible = Thread::factory()->create(['source' => 'slack']);
    Thread::factory()->create(['source' => 'repository_research']);

    expect(Thread::query()->listed()->pluck('id')->all())->toBe([$visible->id]);
});

it('backfills a thread from its request', function () {
    $user = User::factory()->create();
    $thread = Thread::factory()->create(['created_by_user_id' => null]);
    YakTask::factory()->create(['thread_id' => $thread->id, 'source' => 'linear', 'external_id' => 'LINEAR-ENG-9', 'started_by_user_id' => $user->id]);

    app(BackfillThreadOrigin::class)();
    app(BackfillThreadOrigin::class)();

    $thread->refresh();
    expect($thread->source)->toBe(ThreadSource::Linear)
        ->and($thread->source_reference)->toBe('ENG-9')
        ->and($thread->created_by_user_id)->toBe($user->id)
        ->and($thread->starter_name)->toBeNull();
});

it('backfills task-less threads by parent and device and keeps existing sources', function () {
    $parent = Thread::factory()->create();
    $child = Thread::factory()->create(['parent_thread_id' => $parent->id]);
    $device = Thread::factory()->create(['owner_side' => ThreadOwnerSide::Device]);
    $plain = Thread::factory()->create();
    $kept = Thread::factory()->create(['source' => 'teleport']);

    app(BackfillThreadOrigin::class)();

    expect($child->refresh()->source)->toBe(ThreadSource::Stack)
        ->and($device->refresh()->source)->toBe(ThreadSource::Teleport)
        ->and($plain->refresh()->source)->toBe(ThreadSource::Dashboard)
        ->and($kept->refresh()->source)->toBe(ThreadSource::Teleport);
});

it('shapes the origin row fields', function () {
    $user = User::factory()->create();
    $byUser = Thread::factory()->create(['source' => 'sentry', 'source_reference' => '123', 'source_url' => 'https://s.test/1', 'created_by_user_id' => $user->id]);
    $byName = Thread::factory()->create(['starter_name' => 'Ada', 'created_by_user_id' => null]);
    $byYak = Thread::factory()->create(['created_by_user_id' => null]);

    expect(ThreadOriginData::for($byUser))->toBe([
        'source' => ['key' => 'sentry', 'label' => 'Sentry', 'reference' => '123', 'url' => 'https://s.test/1'],
        'starter' => ['key' => 'user:' . $user->id, 'name' => $user->name, 'avatarUrl' => null],
    ])
        ->and(ThreadOriginData::for($byName)['starter'])->toBe(['key' => 'name:Ada', 'name' => 'Ada', 'avatarUrl' => null])
        ->and(ThreadOriginData::for($byYak)['starter'])->toBe(['key' => 'yak', 'name' => 'Yak', 'avatarUrl' => null]);
});
