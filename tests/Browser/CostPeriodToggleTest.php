<?php

use App\Enums\TaskStatus;
use App\Models\User;
use App\Models\YakTask;

beforeEach(function () {
    $this->actingAs(User::factory()->create());

    // Spread tasks across several months so daily, weekly and monthly views
    // each have rows to show. Without data in range every period renders an
    // identical empty table, which would hide a broken toggle.
    foreach ([1, 3, 10, 20, 45, 100] as $daysAgo) {
        YakTask::factory()->create([
            'status' => TaskStatus::Success,
            'repo' => 'acme/widgets',
            'cost_usd' => 1.25,
            'created_at' => now()->subDays($daysAgo),
            'updated_at' => now()->subDays($daysAgo),
        ]);
    }
});

test('the period toggle switches the cost dashboard between daily, weekly and monthly', function () {
    $page = visit('/costs');

    $page->assertNoJavaScriptErrors();
    $page->assertSee('Spend · daily view');

    $page->click('Weekly');
    $page->assertSee('Spend · weekly view');

    $page->click('Monthly');
    $page->assertSee('Spend · monthly view');

    $page->assertNoJavaScriptErrors();
});

test('the dashboard says Claude Code figures are estimates covered by the subscription', function () {
    $page = visit('/costs');

    $page->assertSee('Claude Code figures are estimates, not a bill.');
    $page->assertSee('Claude Code (est. list price)');
    $page->assertNoJavaScriptErrors();
});
