<?php

use App\Livewire\CostDashboard;
use App\Models\DailyCost;
use App\Models\User;
use App\Models\YakTask;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

test('cost dashboard is accessible at /costs', function () {
    $this->get('/costs')->assertOk();
});

test('cost aggregation shows correct summary', function () {
    YakTask::factory()->count(3)->create([
        'cost_usd' => 2.5000,
        'duration_ms' => 120000,
        'created_at' => now(),
    ]);

    Livewire::test(CostDashboard::class)
        ->assertSee('$7.50')
        ->assertSee('3');
});

test('date filtering switches between daily, weekly, monthly', function () {
    YakTask::factory()->create([
        'cost_usd' => 1.0000,
        'created_at' => now(),
    ]);

    YakTask::factory()->create([
        'cost_usd' => 5.0000,
        'created_at' => now()->subDays(35),
    ]);

    $component = Livewire::test(CostDashboard::class);
    $summary = $component->get('summary');
    expect((float) $summary['total_cost'])->toBe(1.00);

    $component->call('setPeriod', 'monthly');
    $summary = $component->get('summary');
    expect((float) $summary['total_cost'])->toBe(6.00);
});

test('per-repo breakdown filters correctly', function () {
    YakTask::factory()->create([
        'cost_usd' => 3.0000,
        'repo' => 'my-app',
        'created_at' => now(),
    ]);

    YakTask::factory()->create([
        'cost_usd' => 7.0000,
        'repo' => 'other-app',
        'created_at' => now(),
    ]);

    $component = Livewire::test(CostDashboard::class);
    $summary = $component->get('summary');
    expect((float) $summary['total_cost'])->toBe(10.00);

    $component->set('repo', 'my-app');
    $summary = $component->get('summary');
    expect((float) $summary['total_cost'])->toBe(3.00);
});

test('per-source breakdown shows in table', function () {
    YakTask::factory()->create([
        'source' => 'slack',
        'cost_usd' => 2.0000,
        'created_at' => now(),
    ]);

    YakTask::factory()->create([
        'source' => 'linear',
        'cost_usd' => 3.0000,
        'created_at' => now(),
    ]);

    Livewire::test(CostDashboard::class)
        ->assertSee('$2.00')
        ->assertSee('$3.00')
        ->assertSee('$5.00');
});

test('chart data returns daily costs', function () {
    DailyCost::create([
        'date' => now()->toDateString(),
        'total_usd' => 4.5000,
        'task_count' => 5,
    ]);

    DailyCost::create([
        'date' => now()->subDay()->toDateString(),
        'total_usd' => 2.1000,
        'task_count' => 3,
    ]);

    expect(DailyCost::count())->toBe(2);

    $component = Livewire::test(CostDashboard::class);
    $chartData = $component->instance()->chartData();
    expect($chartData)->toHaveCount(2);
});

test('average duration is shown', function () {
    YakTask::factory()->create([
        'cost_usd' => 1.0000,
        'duration_ms' => 660000,
        'created_at' => now(),
    ]);

    Livewire::test(CostDashboard::class)
        ->assertSee('11m');
});

test('source filter narrows results', function () {
    YakTask::factory()->create([
        'source' => 'slack',
        'cost_usd' => 2.0000,
        'created_at' => now(),
    ]);

    YakTask::factory()->create([
        'source' => 'sentry',
        'cost_usd' => 8.0000,
        'created_at' => now(),
    ]);

    $component = Livewire::test(CostDashboard::class);
    $component->set('source', 'slack');
    $summary = $component->get('summary');
    expect((float) $summary['total_cost'])->toBe(2.00);
});

test('guests cannot access cost dashboard', function () {
    auth()->logout();
    $this->get('/costs')->assertRedirect('/auth/google');
});
