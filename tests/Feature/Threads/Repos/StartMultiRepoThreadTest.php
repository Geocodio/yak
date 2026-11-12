<?php

use App\Enums\RepositoryRole;
use App\Enums\RepositorySetupStatus;
use App\Models\ClaudeAccount;
use App\Models\Repository;
use App\Models\Thread;
use App\Models\ThreadRepository;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $keyPair = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($keyPair, $privateKey);
    config()->set('yak.channels.github.app_id', '999');
    config()->set('yak.channels.github.private_key', $privateKey);
    config()->set('yak.channels.github.installation_id', 42);

    Queue::fake();
    $this->user = User::factory()->create();
    $this->account = ClaudeAccount::factory()->create(['owner_user_id' => $this->user->id]);
    foreach (['acme/app', 'acme/web', 'acme/docs', 'acme/hidden'] as $slug) {
        Repository::factory()->create(['slug' => $slug]);
    }

    Http::fake([
        'api.github.com/app/installations/42/access_tokens' => fn (Request $request) => Http::response(['token' => 'ghs_x', 'expires_at' => now()->addHour()->toIso8601String()], 201),
        'api.github.com/repos/acme/hidden' => Http::response([], 404),
        'api.github.com/repos/*' => Http::response(['description' => null, 'topics' => []]),
    ]);
});

function startPayload(array $overrides = []): array
{
    return ['repo' => 'acme/app', 'mode' => 'fix', 'description' => 'Change both repos', 'claude_account_id' => test()->account->id, ...$overrides];
}

it('creates a primary, a changes and a context row', function () {
    $response = $this->actingAs($this->user)->post(route('threads.store'), startPayload([
        'repositories' => [['repo' => 'acme/web', 'role' => 'changes'], ['repo' => 'acme/docs', 'role' => 'context']],
    ]));

    $thread = Thread::query()->latest('id')->firstOrFail();
    $response->assertRedirect()->assertSessionHas('startedThreadId', $thread->id);

    $rows = $thread->repositories()->orderBy('id')->get();
    expect($rows->pluck('repo')->all())->toBe(['acme/app', 'acme/web', 'acme/docs'])
        ->and($rows->pluck('role')->all())->toBe([RepositoryRole::Primary, RepositoryRole::Changes, RepositoryRole::Context])
        ->and($rows->pluck('sandbox_path')->all())->toBe(['/workspace', '/extras/web', '/extras/docs'])
        ->and($rows[1]->added_by)->toBe('start')
        ->and($rows[1]->setup_status)->toBe(RepositorySetupStatus::Pending);
});

it('gives extras with the same short name distinct sandbox paths', function () {
    Repository::factory()->create(['slug' => 'other/web']);
    $this->actingAs($this->user)->post(route('threads.store'), startPayload([
        'repositories' => [['repo' => 'acme/web', 'role' => 'changes'], ['repo' => 'other/web', 'role' => 'changes']],
    ]));

    $thread = Thread::query()->latest('id')->firstOrFail();

    expect($thread->repositories()->orderBy('id')->pluck('sandbox_path')->all())->toBe(['/workspace', '/extras/web', '/extras/other-web']);
});

it('rejects an extra Yak cannot reach and creates nothing', function () {
    $this->actingAs($this->user)->post(route('threads.store'), startPayload([
        'repositories' => [['repo' => 'acme/web', 'role' => 'changes'], ['repo' => 'acme/hidden', 'role' => 'context']],
    ]))->assertSessionHasErrors(['repositories.1.repo' => 'Install the Yak GitHub app on acme/hidden, or ask an admin.']);

    expect(Thread::query()->count())->toBe(0);
});

it('rejects a duplicate extra and the primary repeated', function () {
    $this->actingAs($this->user)->post(route('threads.store'), startPayload([
        'repositories' => [['repo' => 'acme/web', 'role' => 'changes'], ['repo' => 'acme/web', 'role' => 'context']],
    ]))->assertSessionHasErrors();

    $this->actingAs($this->user)->post(route('threads.store'), startPayload([
        'repositories' => [['repo' => 'acme/app', 'role' => 'context']],
    ]))->assertSessionHasErrors('repositories.0.repo');

    expect(Thread::query()->count())->toBe(0);
});

it('rejects an unknown role', function () {
    $this->actingAs($this->user)->post(route('threads.store'), startPayload([
        'repositories' => [['repo' => 'acme/web', 'role' => 'primary']],
    ]))->assertSessionHasErrors('repositories.0.role');
});

it('treats every extra as context in research mode', function () {
    $this->actingAs($this->user)->post(route('threads.store'), startPayload([
        'mode' => 'research',
        'repositories' => [['repo' => 'acme/web', 'role' => 'changes']],
    ]));

    expect(Thread::query()->latest('id')->firstOrFail()->repositories()->where('repo', 'acme/web')->value('role'))->toBe(RepositoryRole::Context);
});

it('keeps the redirect for a single repository', function () {
    $response = $this->actingAs($this->user)->post(route('threads.store'), startPayload());

    $response->assertRedirect(route('threads.show', Thread::query()->latest('id')->firstOrFail()));
});

it('answers the repository access check as JSON', function () {
    $this->actingAs($this->user)->getJson(route('threads.repository-access', ['repo' => 'acme/web']))
        ->assertOk()->assertExactJson(['allowed' => true, 'reason' => null]);

    $this->actingAs($this->user)->getJson(route('threads.repository-access', ['repo' => 'acme/hidden']))
        ->assertOk()->assertJson(['allowed' => false, 'reason' => 'Install the Yak GitHub app on acme/hidden, or ask an admin.']);
});

it('reports per-repository progress', function () {
    $thread = Thread::factory()->create(['owner_user_id' => $this->user->id]);
    ThreadRepository::factory()->create(['thread_id' => $thread->id, 'repo' => 'acme/app', 'setup_status' => RepositorySetupStatus::Ready]);
    ThreadRepository::factory()->create(['thread_id' => $thread->id, 'repo' => 'acme/web', 'role' => RepositoryRole::Changes, 'setup_status' => RepositorySetupStatus::Pending]);

    $this->actingAs($this->user)->getJson(route('threads.repositories.progress', $thread))
        ->assertOk()->assertExactJson(['repositories' => [
            ['repo' => 'acme/app', 'role' => 'primary', 'status' => 'ready'],
            ['repo' => 'acme/web', 'role' => 'changes', 'status' => 'queued'],
        ], 'isReady' => false]);

    ThreadRepository::query()->where('repo', 'acme/web')->update(['setup_status' => RepositorySetupStatus::Failed]);
    $this->actingAs($this->user)->getJson(route('threads.repositories.progress', $thread))
        ->assertJsonPath('isReady', false)->assertJsonPath('repositories.1.status', 'failed');

    ThreadRepository::query()->update(['setup_status' => RepositorySetupStatus::Ready]);
    $this->actingAs($this->user)->getJson(route('threads.repositories.progress', $thread))->assertJsonPath('isReady', true);
});

it('hides progress of a private thread from other users and admins', function () {
    $thread = Thread::factory()->private($this->user)->create();
    $admin = User::factory()->create();
    config(['claude-accounts.admin_user_ids' => [$admin->id]]);

    $this->actingAs(User::factory()->create())->getJson(route('threads.repositories.progress', $thread))->assertNotFound();
    $this->actingAs($admin)->getJson(route('threads.repositories.progress', $thread))->assertNotFound();
});
