<?php

use App\Livewire\Health;
use App\Models\Repository;
use App\Models\User;
use App\Models\YakTask;
use Illuminate\Support\Facades\Process;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
    Process::fake([
        'pgrep *' => Process::result(output: '12345'),
        'git ls-remote *' => Process::result(output: 'abc123 HEAD'),
        'claude *' => Process::result(output: 'claude v1.0.0'),
    ]);
});

test('health page renders overall status', function () {
    Livewire::test(Health::class)
        ->assertSee('All Systems Operational')
        ->assertSee('Health');
});

test('health page shows all check names', function () {
    Livewire::test(Health::class)
        ->assertSee('Queue Worker')
        ->assertSee('Last Task Completed')
        ->assertSee('Repositories Fetchable')
        ->assertSee('Claude CLI')
        ->assertSee('MCP Servers');
});

test('health page shows queue worker running', function () {
    Livewire::test(Health::class)
        ->assertSee('Running, PID 12345');
});

test('health page shows queue worker not running', function () {
    Process::fake([
        'pgrep *' => Process::result(exitCode: 1),
        'claude *' => Process::result(output: 'claude v1.0.0'),
    ]);

    Livewire::test(Health::class)
        ->assertSee('Not running')
        ->assertSee('Issues Detected');
});

test('health page shows last completed task', function () {
    YakTask::factory()->success()->create([
        'external_id' => 'GEO-1234',
    ]);

    Livewire::test(Health::class)
        ->assertSee('GEO-1234');
});

test('health page shows no completed tasks message', function () {
    Livewire::test(Health::class)
        ->assertSee('No completed tasks yet');
});

test('health page shows repositories fetchable', function () {
    Repository::factory()->create(['is_active' => true, 'slug' => 'test-repo']);

    Livewire::test(Health::class)
        ->assertSee('1/1 active repositories OK');
});

test('health page shows claude cli responding', function () {
    Livewire::test(Health::class)
        ->assertSee('Responding, claude v1.0.0');
});

test('health page shows issues when checks fail', function () {
    Process::fake([
        'pgrep *' => Process::result(exitCode: 1),
        'claude *' => Process::result(exitCode: 1),
    ]);

    Livewire::test(Health::class)
        ->assertSee('Issues Detected')
        ->assertSee('Not running')
        ->assertSee('Not responding');
});

test('health page requires authentication', function () {
    auth()->logout();

    $this->get(route('health'))
        ->assertRedirect(route('login'));
});
