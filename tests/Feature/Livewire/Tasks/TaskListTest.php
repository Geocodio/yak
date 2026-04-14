<?php

use App\Enums\TaskStatus;
use App\Livewire\Tasks\TaskList;
use App\Models\User;
use App\Models\YakTask;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

test('it renders tasks table', function () {
    $task = YakTask::factory()->running()->create([
        'description' => 'Fix something important',
        'repo' => 'my-repo',
        'source' => 'slack',
        'external_id' => 'SLACK-001',
    ]);

    Livewire::test(TaskList::class)
        ->assertSee('Fix something important')
        ->assertSee('my-repo')
        ->assertSee('Slack')
        ->assertSee('SLACK-001');
});

test('it filters by status', function () {
    YakTask::factory()->running()->create(['description' => 'Running task']);
    YakTask::factory()->success()->create(['description' => 'Success task']);

    Livewire::test(TaskList::class)
        ->assertSee('Running task')
        ->assertSee('Success task')
        ->set('status', 'running')
        ->assertSee('Running task')
        ->assertDontSee('Success task');
});

test('it filters by source', function () {
    YakTask::factory()->create(['source' => 'slack', 'description' => 'Slack task']);
    YakTask::factory()->create(['source' => 'sentry', 'description' => 'Sentry task']);

    Livewire::test(TaskList::class)
        ->assertSee('Slack task')
        ->assertSee('Sentry task')
        ->set('source', 'slack')
        ->assertSee('Slack task')
        ->assertDontSee('Sentry task');
});

test('it filters by repo', function () {
    YakTask::factory()->create(['repo' => 'api', 'description' => 'API task']);
    YakTask::factory()->create(['repo' => 'web', 'description' => 'Web task']);

    Livewire::test(TaskList::class)
        ->assertSee('API task')
        ->assertSee('Web task')
        ->set('repo', 'api')
        ->assertSee('API task')
        ->assertDontSee('Web task');
});

test('it paginates tasks', function () {
    YakTask::factory()->count(51)->create();

    Livewire::test(TaskList::class)
        ->assertSee('Next');
});

test('it uses polling', function () {
    Livewire::test(TaskList::class)
        ->assertSeeHtml('wire:poll.15s');
});

test('it shows status badges with correct labels', function () {
    foreach (TaskStatus::cases() as $status) {
        YakTask::factory()->create(['status' => $status]);
    }

    $component = Livewire::test(TaskList::class);

    foreach (TaskStatus::cases() as $status) {
        $component->assertSee(str_replace('_', ' ', $status->value));
    }
});

test('it shows pr link when available', function () {
    YakTask::factory()->success()->create([
        'pr_url' => 'https://github.com/org/repo/pull/123',
        'description' => 'Task with PR',
    ]);

    Livewire::test(TaskList::class)
        ->assertSeeHtml('href="https://github.com/org/repo/pull/123"')
        ->assertSee('PR');
});

test('it shows dash when no pr', function () {
    YakTask::factory()->create(['pr_url' => null]);

    Livewire::test(TaskList::class)
        ->assertSee('—');
});

test('it sorts by creation date descending', function () {
    YakTask::factory()->create([
        'description' => 'Older task',
        'created_at' => now()->subDay(),
    ]);
    YakTask::factory()->create([
        'description' => 'Newer task',
        'created_at' => now(),
    ]);

    Livewire::test(TaskList::class)
        ->assertSeeInOrder(['Newer task', 'Older task']);
});

test('it is accessible at /tasks route', function () {
    $response = $this->get('/tasks');

    $response->assertOk();
    $response->assertSeeLivewire(TaskList::class);
});

test('it requires authentication', function () {
    auth()->logout();

    $this->get('/tasks')->assertRedirect(route('login'));
});

test('it formats duration correctly', function () {
    expect(TaskList::formatDuration(null))->toBe('—')
        ->and(TaskList::formatDuration(0))->toBe('—')
        ->and(TaskList::formatDuration(30000))->toBe('1m')
        ->and(TaskList::formatDuration(180000))->toBe('3m')
        ->and(TaskList::formatDuration(3600000))->toBe('1h')
        ->and(TaskList::formatDuration(5400000))->toBe('1h 30m');
});

test('it resets page when filter changes', function () {
    YakTask::factory()->count(51)->create(['source' => 'slack']);

    Livewire::test(TaskList::class)
        ->call('gotoPage', 2)
        ->set('status', 'pending')
        ->assertNotSet('page', 2);
});
