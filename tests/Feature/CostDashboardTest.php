<?php

use App\Livewire\CostDashboard;
use App\Models\AiUsage;
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
    $this->get('/costs')->assertRedirect('/login');
});

test('success rate is calculated correctly', function () {
    YakTask::factory()->create([
        'status' => 'success',
        'created_at' => now(),
    ]);

    YakTask::factory()->create([
        'status' => 'failed',
        'created_at' => now(),
    ]);

    $component = Livewire::test(CostDashboard::class);
    $summary = $component->get('summary');
    expect($summary['success_rate'])->toBe('50%');
});

test('clarification rate is calculated correctly', function () {
    YakTask::factory()->create([
        'status' => 'awaiting_clarification',
        'created_at' => now(),
    ]);

    YakTask::factory()->count(3)->create([
        'status' => 'success',
        'created_at' => now(),
    ]);

    $component = Livewire::test(CostDashboard::class);
    $summary = $component->get('summary');
    expect($summary['clarification_rate'])->toBe('25%');
});

test('dashboard shows success and clarification rate cards', function () {
    YakTask::factory()->create([
        'status' => 'success',
        'created_at' => now(),
    ]);

    Livewire::test(CostDashboard::class)
        ->assertSee('Success Rate')
        ->assertSee('Clarification Rate');
});

test('api spend summary sums only in range', function () {
    AiUsage::factory()->create([
        'cost_usd' => 0.0015,
        'created_at' => now(),
    ]);
    AiUsage::factory()->create([
        'cost_usd' => 0.0025,
        'created_at' => now(),
    ]);
    AiUsage::factory()->create([
        'cost_usd' => 100.00,
        'created_at' => now()->subDays(40),
    ]);

    $component = Livewire::test(CostDashboard::class);
    $apiSpend = $component->get('apiSpendSummary');

    expect((float) $apiSpend['total_cost'])->toBe(0.004);
    expect($apiSpend['call_count'])->toBe(2);
});

test('api spend respects source filter via task join', function () {
    $slackTask = YakTask::factory()->create(['source' => 'slack']);
    $linearTask = YakTask::factory()->create(['source' => 'linear']);
    $orphan = null;

    AiUsage::factory()->create([
        'yak_task_id' => $slackTask->id,
        'cost_usd' => 0.0010,
        'created_at' => now(),
    ]);
    AiUsage::factory()->create([
        'yak_task_id' => $linearTask->id,
        'cost_usd' => 0.0050,
        'created_at' => now(),
    ]);
    AiUsage::factory()->create([
        'yak_task_id' => $orphan,
        'cost_usd' => 0.9999,
        'created_at' => now(),
    ]);

    $component = Livewire::test(CostDashboard::class, ['source' => 'slack']);
    $apiSpend = $component->get('apiSpendSummary');

    expect((float) $apiSpend['total_cost'])->toBe(0.001);
    expect($apiSpend['call_count'])->toBe(1);
});

test('api spend breakdown groups by date', function () {
    AiUsage::factory()->count(2)->create(['cost_usd' => 0.0010, 'created_at' => now()]);
    AiUsage::factory()->create(['cost_usd' => 0.0040, 'created_at' => now()->subDay()]);

    $component = Livewire::test(CostDashboard::class);
    $breakdown = $component->get('apiSpendBreakdown');

    expect($breakdown)->toHaveCount(2);
    expect($breakdown[0]->call_count + $breakdown[1]->call_count)->toBe(3);
});
