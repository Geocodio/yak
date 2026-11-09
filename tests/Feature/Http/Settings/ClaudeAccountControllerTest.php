<?php

use App\Enums\RunnerState;
use App\Enums\TurnState;
use App\Models\ClaudeAccount;
use App\Models\TaskRun;
use App\Models\Thread;
use App\Models\User;
use App\Models\YakTask;
use App\Services\Threads\ThreadStreams;
use Illuminate\Support\Facades\Process;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\FakeThreadStreams;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    Process::fake(['*' => Process::result(output: '{"is_error":false,"result":"OK"}')]);
});

it('lists only my accounts and system accounts', function () {
    $mine = ClaudeAccount::factory()->create(['owner_user_id' => $this->user->id]);
    $system = ClaudeAccount::factory()->system()->create();
    ClaudeAccount::factory()->create();

    $this->get(route('settings.claude-accounts'))->assertInertia(fn (Assert $page) => $page
        ->component('Settings/ClaudeAccounts', false)
        ->has('accounts', 2)
        ->where('accounts', fn ($accounts) => collect($accounts)->pluck('id')->sort()->values()->all() === [$mine->id, $system->id]));
});

it('never sends the secret to the page', function () {
    $account = ClaudeAccount::factory()->create(['owner_user_id' => $this->user->id, 'secret' => 'sk-ant-oat01-hidden']);

    $response = $this->get(route('settings.claude-accounts'));

    expect($response->getContent())->not->toContain('sk-ant-oat01-hidden');
    $response->assertInertia(fn (Assert $page) => $page->missing('accounts.0.secret')->where('accounts.0.id', $account->id));
});

it('saves a validated account with a one year expiry', function () {
    $this->post(route('settings.claude-accounts.store'), ['name' => 'Mine', 'kind' => 'setup_token', 'secret' => 'tok'])
        ->assertRedirect(route('settings.claude-accounts'));

    $account = ClaudeAccount::query()->firstOrFail();
    expect($account->owner_user_id)->toBe($this->user->id)
        ->and($account->secret)->toBe('tok')
        ->and($account->expires_at->isSameDay(now()->addYear()))->toBeTrue();
});

it('does not save an account that fails validation', function () {
    Process::fake(['*' => Process::result(output: 'nope', exitCode: 1)]);

    $this->post(route('settings.claude-accounts.store'), ['name' => 'Bad', 'kind' => 'api_key', 'secret' => 'bad'])
        ->assertSessionHasErrors('secret');

    expect(ClaudeAccount::query()->count())->toBe(0);
});

it('rotates my account after validating the new secret', function () {
    $account = ClaudeAccount::factory()->invalid()->create(['owner_user_id' => $this->user->id]);

    $this->put(route('settings.claude-accounts.rotate', $account), ['secret' => 'fresh'])->assertRedirect();

    expect($account->fresh())->secret->toBe('fresh')->status->value->toBe('active')->last_error->toBeNull();
});

it('forbids rotating another person\'s account', function () {
    $account = ClaudeAccount::factory()->create();

    $this->put(route('settings.claude-accounts.rotate', $account), ['secret' => 'x'])->assertForbidden();
    $this->delete(route('settings.claude-accounts.destroy', $account))->assertForbidden();
});

it('keeps one default when I pick a new one', function () {
    $first = ClaudeAccount::factory()->create(['owner_user_id' => $this->user->id, 'is_default' => true]);
    $second = ClaudeAccount::factory()->create(['owner_user_id' => $this->user->id]);

    $this->patch(route('settings.claude-accounts.update', $second), ['is_default' => true])->assertRedirect();

    expect($first->fresh()->is_default)->toBeFalse()->and($second->fresh()->is_default)->toBeTrue();
});

it('removes my account', function () {
    $account = ClaudeAccount::factory()->create(['owner_user_id' => $this->user->id]);

    $this->delete(route('settings.claude-accounts.destroy', $account))->assertRedirect();

    expect(ClaudeAccount::query()->count())->toBe(0);
});

it('keeps system accounts away from users who are not system account admins', function () {
    $system = ClaudeAccount::factory()->system()->create();

    $this->post(route('settings.claude-accounts.store'), ['name' => 'Shared', 'kind' => 'setup_token', 'secret' => 'tok', 'is_system' => true])->assertForbidden();
    $this->put(route('settings.claude-accounts.rotate', $system), ['secret' => 'fresh'])->assertForbidden();
    $this->patch(route('settings.claude-accounts.update', $system), ['name' => 'Renamed'])->assertForbidden();
    $this->delete(route('settings.claude-accounts.destroy', $system))->assertForbidden();

    expect(ClaudeAccount::query()->count())->toBe(1);
});

it('lets a system account admin manage system accounts', function () {
    config()->set('yak.system_claude_account_admin_ids', [$this->user->id]);
    $system = ClaudeAccount::factory()->system()->create();

    $this->post(route('settings.claude-accounts.store'), ['name' => 'Shared', 'kind' => 'setup_token', 'secret' => 'tok', 'is_system' => true])->assertRedirect();
    $this->delete(route('settings.claude-accounts.destroy', $system))->assertRedirect();

    expect(ClaudeAccount::query()->whereNull('owner_user_id')->count())->toBe(1);
});

it('stops a resting runner that holds a removed account but leaves a working one alone', function () {
    $streams = new FakeThreadStreams;
    $this->app->instance(ThreadStreams::class, $streams);
    $account = ClaudeAccount::factory()->create(['owner_user_id' => $this->user->id]);
    $resting = Thread::factory()->paused()->create(['claude_account_id' => $account->id, 'runner_state' => RunnerState::Idle]);
    $working = Thread::factory()->paused()->create(['claude_account_id' => $account->id, 'runner_state' => RunnerState::Busy]);
    $task = YakTask::factory()->running()->create(['thread_id' => $working->id]);
    TaskRun::factory()->create(['yak_task_id' => $task->id, 'thread_id' => $working->id, 'turn_state' => TurnState::Running]);

    $this->delete(route('settings.claude-accounts.destroy', $account))->assertRedirect();

    expect(array_column($streams->commandsFor($resting->channel()), 'type'))->toBe(['kill'])
        ->and($resting->fresh()->runner_state)->toBe(RunnerState::None)
        ->and($streams->commandsFor($working->channel()))->toBe([]);
});

it('stores a chosen icon and colour', function () {
    $this->post(route('settings.claude-accounts.store'), ['name' => 'Mine', 'kind' => 'setup_token', 'secret' => 'tok', 'icon' => 'rocket', 'color' => 'ok']);

    $account = ClaudeAccount::query()->firstOrFail();
    expect($account->icon->value)->toBe('rocket')->and($account->color->value)->toBe('ok');

    $this->get(route('settings.claude-accounts'))->assertInertia(fn (Assert $page) => $page
        ->where('accounts.0.icon', 'rocket')->where('accounts.0.color', 'ok'));
});

it('rejects an icon outside the set', function () {
    $this->post(route('settings.claude-accounts.store'), ['name' => 'Mine', 'kind' => 'setup_token', 'secret' => 'tok', 'icon' => 'skull'])
        ->assertSessionHasErrors('icon');
});

it('rejects a colour outside the set', function () {
    $this->post(route('settings.claude-accounts.store'), ['name' => 'Mine', 'kind' => 'setup_token', 'secret' => 'tok', 'color' => 'purple'])
        ->assertSessionHasErrors('color');
});

it('defaults to sparkles and accent', function () {
    $this->post(route('settings.claude-accounts.store'), ['name' => 'Mine', 'kind' => 'setup_token', 'secret' => 'tok']);

    $account = ClaudeAccount::query()->firstOrFail()->fresh();
    expect($account->icon->value)->toBe('sparkles')->and($account->color->value)->toBe('accent');
});

it('changes the icon and colour without a secret', function () {
    $account = ClaudeAccount::factory()->create(['owner_user_id' => $this->user->id]);

    $this->patch(route('settings.claude-accounts.update', $account), ['icon' => 'zap', 'color' => 'warn']);

    expect($account->fresh()->icon->value)->toBe('zap')->and($account->fresh()->color->value)->toBe('warn');
});
