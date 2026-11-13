<?php

use App\Enums\TeleportDirection;
use App\Enums\TeleportState;
use App\Enums\ThreadOwnerSide;
use App\Enums\ThreadStatus;
use App\Enums\ThreadVisibility;
use App\Models\Repository;
use App\Models\Teleport;
use App\Models\Thread;
use App\Models\ThreadSession;
use App\Models\YakTask;
use App\Services\Teleport\TeleportThreadCreator;

const BEGIN_SESSION = 'c2c2c2c2-0000-4000-8000-000000000003';

beforeEach(function () {
    [$this->device, $this->token] = makeDevice();
    Repository::factory()->create(['slug' => 'acme/widgets', 'github_full_name' => 'acme/widgets', 'git_url' => 'https://github.com/acme/widgets.git']);
});

function beginPayload(array $overrides = []): array
{
    return array_replace_recursive([
        'direction' => 'up',
        'session_id' => BEGIN_SESSION,
        'title_hint' => 'Fix the geocode cache',
        'git_name' => 'Ada',
        'git_email' => 'ada@example.com',
        'local_path' => '/Users/ada/widgets',
        'repos' => [['repo' => 'acme/widgets', 'role' => 'primary', 'local_path' => '/Users/ada/widgets', 'branch' => 'main', 'on_default_branch' => true, 'head_sha' => 'abc123']],
    ], $overrides);
}

it('creates a private device-owned thread and teleport task', function () {
    $response = $this->postJson('/api/cli/v1/teleports', beginPayload(), cliHeaders($this->token))->assertOk();

    $thread = Thread::withoutGlobalScopes()->findOrFail($response->json('thread.id'));
    expect($thread->visibility)->toBe(ThreadVisibility::Private)
        ->and($thread->owner_user_id)->toBe($this->device->user_id)
        ->and($thread->owner_side)->toBe(ThreadOwnerSide::Device)
        ->and($thread->owner_device_id)->toBe($this->device->id)
        ->and($thread->git_author_name)->toBe('Ada')
        ->and($thread->local_repo_path)->toBe('/Users/ada/widgets')
        ->and($thread->title)->toBe('Fix the geocode cache')
        ->and($thread->branch)->toBeNull();
    expect(YakTask::query()->where('thread_id', $thread->id)->first())
        ->source->toBe(YakTask::SOURCE_TELEPORT);
    expect(ThreadSession::query()->where(['thread_id' => $thread->id, 'session_id' => BEGIN_SESSION])->exists())->toBeTrue();
    expect($thread->repositories()->first())->sandbox_path->toBe('/workspace');

    $response->assertJsonPath('thread.is_new', true)
        ->assertJsonPath('thread.slug', 'fix-the-geocode-cache')
        ->assertJsonPath('thread.visibility', 'private')
        ->assertJsonPath('thread.first_teleported_at', null)
        ->assertJsonPath('resumed', false)
        ->assertJsonPath('repos.0.branch', 'yak/fix-the-geocode-cache')
        ->assertJsonPath('repos.0.create_branch', true)
        ->assertJsonPath('repos.0.clone_url', 'https://github.com/acme/widgets.git')
        ->assertJsonPath('repos.0.sandbox_path', '/workspace');
    expect(Teleport::query()->find($response->json('teleport_id')))
        ->direction->toBe(TeleportDirection::Up)
        ->state->toBe(TeleportState::Open);
});

it('yields continued_from for a closed thread', function () {
    $closed = Thread::factory()->closed()->create(['repo' => 'acme/widgets']);
    ThreadSession::factory()->create(['thread_id' => $closed->id, 'session_id' => BEGIN_SESSION]);
    $closed->update(['owner_user_id' => $this->device->user_id]);

    $response = $this->postJson('/api/cli/v1/teleports', beginPayload(), cliHeaders($this->token))->assertOk();

    $response->assertJsonPath('thread.is_new', true)->assertJsonPath('thread.continued_from_thread_id', $closed->id);
    expect($response->json('thread.id'))->not->toBe($closed->id);
});

it('echoes a non-default branch and gives context repos no branch', function () {
    Repository::factory()->create(['slug' => 'acme/lib', 'github_full_name' => 'acme/lib']);
    $payload = beginPayload([
        'repos' => [['branch' => 'feature/x', 'on_default_branch' => false]],
    ]);
    $payload['repos'][] = ['repo' => 'acme/lib', 'role' => 'context', 'branch' => null, 'on_default_branch' => true, 'head_sha' => null];

    $response = $this->postJson('/api/cli/v1/teleports', $payload, cliHeaders($this->token))->assertOk();

    $response->assertJsonPath('repos.0.branch', 'feature/x')
        ->assertJsonPath('repos.0.create_branch', false)
        ->assertJsonPath('repos.1.branch', null)
        ->assertJsonPath('repos.1.create_branch', false)
        ->assertJsonPath('repos.1.sandbox_path', '/extras/lib');
});

it('treats a branch equal to the default branch as on the default branch whatever the client says', function () {
    Repository::query()->where('slug', 'acme/widgets')->update(['default_branch' => 'develop']);

    $this->postJson('/api/cli/v1/teleports', beginPayload(['repos' => [['branch' => 'develop', 'on_default_branch' => false]]]), cliHeaders($this->token))
        ->assertOk()
        ->assertJsonPath('repos.0.create_branch', true)
        ->assertJsonPath('repos.0.branch', 'yak/fix-the-geocode-cache');
});

it('lets a thread with a branch teleport up from the default branch when the client flag is false', function () {
    Repository::query()->where('slug', 'acme/widgets')->update(['default_branch' => 'develop']);
    $thread = Thread::factory()->create(['owner_user_id' => $this->device->user_id, 'owner_side' => 'device', 'owner_device_id' => $this->device->id, 'branch' => 'yak/x']);
    ThreadSession::factory()->create(['thread_id' => $thread->id, 'session_id' => BEGIN_SESSION]);

    $this->postJson('/api/cli/v1/teleports', beginPayload(['repos' => [['branch' => 'develop', 'on_default_branch' => false]]]), cliHeaders($this->token))
        ->assertOk();
});

it('rejects an unknown repo', function () {
    $this->postJson('/api/cli/v1/teleports', beginPayload(['repos' => [['repo' => 'nope/nothing']]]), cliHeaders($this->token))
        ->assertStatus(422)->assertJsonPath('error', 'repo_not_on_yak')->assertJsonPath('details.repo', 'nope/nothing');
});

it('rejects a thread that Yak owns', function () {
    $thread = Thread::factory()->create(['owner_user_id' => $this->device->user_id]);
    ThreadSession::factory()->create(['thread_id' => $thread->id, 'session_id' => BEGIN_SESSION]);

    $this->postJson('/api/cli/v1/teleports', beginPayload(), cliHeaders($this->token))
        ->assertStatus(409)->assertJsonPath('error', 'thread_owned_by_yak')->assertJsonPath('details.thread_id', $thread->public_id);
});

it('rejects a thread another device owns', function () {
    [$other] = makeDevice($this->device->user);
    $thread = Thread::factory()->create(['owner_user_id' => $this->device->user_id, 'owner_side' => 'device', 'owner_device_id' => $other->id]);
    ThreadSession::factory()->create(['thread_id' => $thread->id, 'session_id' => BEGIN_SESSION]);

    $this->postJson('/api/cli/v1/teleports', beginPayload(), cliHeaders($this->token))
        ->assertStatus(409)->assertJsonPath('error', 'owned_by_other_device')->assertJsonPath('details.device_name', $other->name);
});

it('reports a branch mismatch with both names', function () {
    $thread = Thread::factory()->create(['owner_user_id' => $this->device->user_id, 'owner_side' => 'device', 'owner_device_id' => $this->device->id, 'branch' => 'yak/other']);
    ThreadSession::factory()->create(['thread_id' => $thread->id, 'session_id' => BEGIN_SESSION]);

    $this->postJson('/api/cli/v1/teleports', beginPayload(['repos' => [['branch' => 'feature/y', 'on_default_branch' => false]]]), cliHeaders($this->token))
        ->assertStatus(409)
        ->assertJsonPath('error', 'branch_mismatch')
        ->assertJsonPath('details.thread_branch', 'yak/other')
        ->assertJsonPath('details.current_branch', 'feature/y');
});

it('resumes the same teleport on a repeated begin', function () {
    $first = $this->postJson('/api/cli/v1/teleports', beginPayload(), cliHeaders($this->token))->assertOk();
    $second = $this->postJson('/api/cli/v1/teleports', beginPayload(), cliHeaders($this->token))->assertOk();

    expect($second->json('teleport_id'))->toBe($first->json('teleport_id'))
        ->and($second->json('resumed'))->toBeTrue()
        ->and($second->json('thread.id'))->toBe($first->json('thread.id'))
        ->and($second->json('thread.is_new'))->toBeTrue();
    expect(Teleport::query()->count())->toBe(1);
});

it('lets exactly one of two devices begin on the same session', function () {
    [, $otherToken] = makeDevice($this->device->user);

    $this->postJson('/api/cli/v1/teleports', beginPayload(), cliHeaders($this->token))->assertOk();
    $this->postJson('/api/cli/v1/teleports', beginPayload(), cliHeaders($otherToken))
        ->assertStatus(409)
        ->assertJsonPath('error', 'teleport_in_progress')
        ->assertJsonStructure(['details' => ['teleport_id', 'direction', 'device_name']]);

    expect(Teleport::query()->count())->toBe(1);
});

it('begins again on a device-owned thread after a cancelled teleport', function () {
    $first = $this->postJson('/api/cli/v1/teleports', beginPayload(), cliHeaders($this->token))->assertOk();
    $this->postJson("/api/cli/v1/teleports/{$first->json('teleport_id')}/cancel", ['reason' => 'user_cancelled'], cliHeaders($this->token))->assertOk();

    $second = $this->postJson('/api/cli/v1/teleports', beginPayload(), cliHeaders($this->token))->assertOk();

    expect($second->json('teleport_id'))->not->toBe($first->json('teleport_id'))
        ->and($second->json('thread.id'))->toBe($first->json('thread.id'))
        ->and($second->json('thread.is_new'))->toBeFalse();
    expect(Thread::withoutGlobalScopes()->find($second->json('thread.id'))->status)->toBe(ThreadStatus::Open);
});

it('validates the body', function () {
    $this->postJson('/api/cli/v1/teleports', ['direction' => 'up'], cliHeaders($this->token))
        ->assertStatus(422)->assertJsonPath('error', 'validation_failed');
    $this->postJson('/api/cli/v1/teleports', beginPayload(['repos' => [['role' => 'context']]]), cliHeaders($this->token))
        ->assertStatus(422);
});

it('rejects hostile local paths', function (string $path) {
    Repository::factory()->create(['slug' => 'acme/lib', 'github_full_name' => 'acme/lib']);
    $payload = beginPayload();
    $payload['repos'][] = ['repo' => 'acme/lib', 'role' => 'changes', 'local_path' => $path, 'branch' => 'main', 'head_sha' => 'abc'];

    $this->postJson('/api/cli/v1/teleports', $payload, cliHeaders($this->token))
        ->assertStatus(422)->assertJsonPath('error', 'validation_failed');
    expect(Teleport::query()->count())->toBe(0);
})->with(['../../etc', 'relative/path', "/ok\n/bad", '/a/../b']);

it('rejects the same repo twice', function () {
    $payload = beginPayload();
    $payload['repos'][] = ['repo' => 'Acme/Widgets', 'role' => 'changes', 'local_path' => '/Users/ada/other', 'branch' => 'x', 'head_sha' => 'abc'];

    $this->postJson('/api/cli/v1/teleports', $payload, cliHeaders($this->token))
        ->assertStatus(422)->assertJsonPath('error', 'validation_failed')->assertJsonPath('details.repo', 'Acme/Widgets');
});

it('rejects two repos that share a folder name', function () {
    Repository::factory()->create(['slug' => 'other/widgets', 'github_full_name' => 'other/widgets']);
    $payload = beginPayload();
    $payload['repos'][] = ['repo' => 'other/widgets', 'role' => 'context'];

    $this->postJson('/api/cli/v1/teleports', $payload, cliHeaders($this->token))
        ->assertStatus(422)->assertJsonPath('error', 'validation_failed')->assertJsonPath('details.repo', 'other/widgets');
});

it('accepts a context entry without branch and head and returns branch null', function () {
    Repository::factory()->create(['slug' => 'acme/lib', 'github_full_name' => 'acme/lib']);
    $payload = beginPayload();
    $payload['repos'][] = ['repo' => 'acme/lib', 'role' => 'context', 'local_path' => '/Users/ada/lib'];

    $this->postJson('/api/cli/v1/teleports', $payload, cliHeaders($this->token))
        ->assertOk()->assertJsonPath('repos.1.branch', null);
});

it('localPathOf returns the local path of an added repo and null for unknown', function () {
    $teleport = Teleport::factory()->create(['payload' => ['repos' => [
        ['repo' => 'acme/widgets', 'role' => 'primary', 'local_path' => '/Users/ada/widgets'],
        ['repo' => 'acme/lib', 'role' => 'changes', 'local_path' => '/Users/ada/lib'],
        ['repo' => 'acme/ctx', 'role' => 'context'],
    ]]]);

    expect($teleport->localPathOf('ACME/lib'))->toBe('/Users/ada/lib')
        ->and($teleport->localPathOf('acme/ctx'))->toBeNull()
        ->and($teleport->localPathOf('nope/x'))->toBeNull();
});

it('syncRepositories gives extras with the same short name distinct sandbox paths', function () {
    $acme = Repository::factory()->create(['slug' => 'acme/lib']);
    $other = Repository::factory()->create(['slug' => 'other/lib']);
    $thread = Thread::factory()->create();

    app(TeleportThreadCreator::class)->syncRepositories(
        $thread,
        [['role' => 'changes'], ['role' => 'context']],
        [$acme, $other],
    );

    expect($thread->repositories()->orderBy('id')->pluck('sandbox_path')->all())->toBe(['/extras/lib', '/extras/other-lib']);
});
