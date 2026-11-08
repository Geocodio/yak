<?php

use App\Models\ClaudeAccount;
use App\Models\Repository;
use App\Models\User;
use App\Models\UserRepositorySetting;
use App\Models\YakTask;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Queue::fake();
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    Repository::factory()->create(['slug' => 'web', 'is_active' => true]);
    $this->payload = ['repo' => 'web', 'mode' => 'fix', 'description' => 'Add a CSV export'];
});

it('stores the picked account and model on the new task', function () {
    $account = ClaudeAccount::factory()->create(['owner_user_id' => $this->user->id]);

    $this->post(route('tasks.store'), [...$this->payload, 'claude_account_id' => $account->id, 'model' => 'opus'])->assertRedirect();

    $task = YakTask::query()->firstOrFail();
    expect($task->requested_claude_account_id)->toBe($account->id)->and($task->requested_model)->toBe('opus');
});

it('accepts a system account', function () {
    $system = ClaudeAccount::factory()->system()->create();

    $this->post(route('tasks.store'), [...$this->payload, 'claude_account_id' => $system->id])->assertRedirect();

    expect(YakTask::query()->firstOrFail()->requested_claude_account_id)->toBe($system->id);
});

it("rejects another person's account and an unknown model", function () {
    $theirs = ClaudeAccount::factory()->create();

    $this->post(route('tasks.store'), [...$this->payload, 'claude_account_id' => $theirs->id, 'model' => 'gpt'])
        ->assertSessionHasErrors(['claude_account_id', 'model']);

    expect(YakTask::query()->count())->toBe(0);
});

it('leaves the request empty when nothing is picked', function () {
    $this->post(route('tasks.store'), $this->payload)->assertRedirect();

    $task = YakTask::query()->firstOrFail();
    expect($task->requested_claude_account_id)->toBeNull()->and($task->requested_model)->toBeNull();
});

it('prefills the picker from my repo default', function () {
    $mine = ClaudeAccount::factory()->create(['owner_user_id' => $this->user->id]);
    UserRepositorySetting::factory()->create(['user_id' => $this->user->id, 'repo' => 'web', 'claude_account_id' => $mine->id, 'model' => 'sonnet']);

    $this->get(route('tasks'))->assertInertia(fn (Assert $page) => $page
        ->has('accountPicker.accounts', 1)
        ->where('accountPicker.defaultsByRepo.web', ['accountId' => $mine->id, 'model' => 'sonnet'])
        ->where('accountPicker.models', ['opus', 'sonnet', 'haiku']));
});
