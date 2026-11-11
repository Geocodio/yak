<?php

use App\DataTransferObjects\AccountValidation;
use App\Enums\ClaudeAccountStatus;
use App\Enums\RunnerState;
use App\Enums\TurnState;
use App\Models\ClaudeAccount;
use App\Models\TaskRun;
use App\Models\Thread;
use App\Models\User;
use App\Services\ClaudeAccounts\ClaudeAccountValidator;
use App\Services\Threads\ThreadCommands;
use Inertia\Testing\AssertableInertia as Assert;

const NEW_SECRET = 'sk-ant-oat01-brandnewsecretvalue000000000000';

function fakeValidator(bool $valid = true, ?string $error = null): void
{
    $validator = Mockery::mock(ClaudeAccountValidator::class);
    $validator->shouldReceive('validate')->andReturn(new AccountValidation($valid, $error));
    app()->instance(ClaudeAccountValidator::class, $validator);
}

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    fakeValidator();
});

function storePayload(array $overrides = []): array
{
    return ['name' => 'Work', 'kind' => 'setup_token', 'secret' => NEW_SECRET, 'default_model' => 'sonnet', ...$overrides];
}

it('renders the page with deferred account groups', function () {
    config(['inertia.testing.ensure_pages_exist' => false]);
    $mine = ClaudeAccount::factory()->for($this->user, 'owner')->create(['validated_at' => now()]);
    ClaudeAccount::factory()->create(['validated_at' => now()]);
    $system = ClaudeAccount::factory()->system()->create(['validated_at' => now()]);

    $this->get(route('settings.claude-accounts'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('Settings/ClaudeAccounts')
            ->where('canManageYakAccounts', false)
            ->where('models', ['opus', 'sonnet', 'haiku'])
            ->has('docsUrl')
            ->missing('accounts')
            ->missing('yakAccounts')
            ->loadDeferredProps('accounts', fn (Assert $reload) => $reload
                ->has('accounts', 1)
                ->where('accounts.0.id', $mine->id)
                ->has('yakAccounts', 1)
                ->where('yakAccounts.0.id', $system->id)));
});

it('stores a validated account with an encrypted secret and makes the first one default', function () {
    $this->post(route('settings.claude-accounts.store'), storePayload())->assertRedirect(route('settings.claude-accounts'));

    $account = ClaudeAccount::query()->firstOrFail();
    expect($account->owner_user_id)->toBe($this->user->id)
        ->and($account->secret)->toBe(NEW_SECRET)
        ->and($account->validated_at)->not->toBeNull()
        ->and($account->is_default)->toBeTrue()
        ->and($account->expires_at->isFuture())->toBeTrue()
        ->and(DB::table('claude_accounts')->value('secret'))->not->toContain(NEW_SECRET);
});

it('saves nothing and flashes no secret when validation fails', function () {
    fakeValidator(false, 'Claude rejected this token (401).');

    $response = $this->from(route('settings.claude-accounts'))->post(route('settings.claude-accounts.store'), storePayload());

    $response->assertSessionHasErrors('secret');
    expect(ClaudeAccount::query()->count())->toBe(0)
        ->and(json_encode(session()->all()))->not->toContain(NEW_SECRET);
});

it('rejects a secret with the wrong prefix', function () {
    $this->post(route('settings.claude-accounts.store'), storePayload(['secret' => 'claude-token-1234']))->assertSessionHasErrors('secret');
    expect(ClaudeAccount::query()->count())->toBe(0);
});

it('rejects a duplicate name for the same owner', function () {
    ClaudeAccount::factory()->for($this->user, 'owner')->create(['name' => 'Work']);

    $this->post(route('settings.claude-accounts.store'), storePayload())->assertSessionHasErrors('name');
});

it('lets only admins create system accounts', function () {
    $this->post(route('settings.claude-accounts.store'), storePayload(['is_system' => true]))->assertForbidden();

    config(['claude-accounts.admin_user_ids' => [$this->user->id]]);
    $this->post(route('settings.claude-accounts.store'), storePayload(['is_system' => true]))->assertRedirect();

    expect(ClaudeAccount::query()->firstOrFail()->owner_user_id)->toBeNull();
});

it('rotates a secret, resets status and error, and keeps the old secret on failure', function () {
    $account = ClaudeAccount::factory()->for($this->user, 'owner')->invalid()->create(['secret' => 'sk-ant-oat01-old']);

    fakeValidator(false, 'nope');
    $this->post(route('settings.claude-accounts.rotate', $account), ['secret' => NEW_SECRET])->assertSessionHasErrors('secret');
    expect($account->fresh()->secret)->toBe('sk-ant-oat01-old');

    fakeValidator();
    $this->post(route('settings.claude-accounts.rotate', $account), ['secret' => NEW_SECRET])->assertRedirect();
    $account->refresh();
    expect($account->secret)->toBe(NEW_SECRET)->and($account->status)->toBe(ClaudeAccountStatus::Active)->and($account->last_error)->toBeNull();
});

it('validates the concurrency limit', function (int $limit, bool $accepted) {
    $account = ClaudeAccount::factory()->for($this->user, 'owner')->create();

    $response = $this->patch(route('settings.claude-accounts.update', $account), ['max_concurrent_turns' => $limit]);

    $accepted ? $response->assertSessionHasNoErrors() : $response->assertSessionHasErrors('max_concurrent_turns');
    expect($account->fresh()->max_concurrent_turns)->toBe($accepted ? $limit : 16);
})->with([[0, false], [65, false], [1, true], [64, true]]);

it('moves the default when an account is updated to default', function () {
    $first = ClaudeAccount::factory()->for($this->user, 'owner')->default()->create();
    $second = ClaudeAccount::factory()->for($this->user, 'owner')->create();

    $this->patch(route('settings.claude-accounts.update', $second), ['is_default' => true, 'name' => 'Renamed']);

    expect($first->fresh()->is_default)->toBeFalse()->and($second->fresh()->is_default)->toBeTrue()->and($second->fresh()->name)->toBe('Renamed');
});

it('ends idle runners on destroy, keeps busy ones, and keeps every thread row', function () {
    $account = ClaudeAccount::factory()->for($this->user, 'owner')->create();
    $idle = Thread::factory()->create(['claude_account_id' => $account->id, 'runner_state' => RunnerState::Idle]);
    $busy = Thread::factory()->create(['claude_account_id' => $account->id, 'runner_state' => RunnerState::Idle]);
    TaskRun::factory()->create(['thread_id' => $busy->id, 'turn_state' => TurnState::Running]);

    $commands = Mockery::mock(ThreadCommands::class);
    $commands->shouldReceive('kill')->once()->withArgs(fn (Thread $thread) => $thread->is($idle));
    app()->instance(ThreadCommands::class, $commands);

    $this->delete(route('settings.claude-accounts.destroy', $account))->assertRedirect();

    expect(ClaudeAccount::query()->count())->toBe(0)
        ->and($idle->fresh()->runner_state)->toBe(RunnerState::None)
        ->and($busy->fresh()->runner_state)->toBe(RunnerState::Idle)
        ->and($busy->fresh()->claude_account_id)->toBeNull();
});

it('returns 403 on another person account', function () {
    $theirs = ClaudeAccount::factory()->create();

    $this->post(route('settings.claude-accounts.rotate', $theirs), ['secret' => NEW_SECRET])->assertForbidden();
    $this->patch(route('settings.claude-accounts.update', $theirs), ['name' => 'x'])->assertForbidden();
    $this->delete(route('settings.claude-accounts.destroy', $theirs))->assertForbidden();
    expect($theirs->fresh())->not->toBeNull();
});

it('keeps system accounts away from non-admins', function () {
    $system = ClaudeAccount::factory()->system()->create();

    $this->delete(route('settings.claude-accounts.destroy', $system))->assertForbidden();
});

it('never puts the secret in a response body or the session', function () {
    $this->post(route('settings.claude-accounts.store'), storePayload());
    $account = ClaudeAccount::query()->firstOrFail();

    $body = $this->get(route('settings.claude-accounts'))->getContent();

    expect($body)->not->toContain(NEW_SECRET)->and(json_encode(session()->all()))->not->toContain(NEW_SECRET);
});
