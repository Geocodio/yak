<?php

use App\Enums\TaskStatus;
use App\Models\User;
use App\Models\YakTask;

test('on a phone the composer is one line until it is focused', function () {
    $this->actingAs(User::factory()->create());
    $task = YakTask::factory()->create(['status' => TaskStatus::Running, 'started_at' => now()]);

    // A phone-width `resize()` rather than `->on()->mobile()` -- the layout
    // only depends on viewport width, and the device emulation's touch
    // input doesn't focus a textarea on `click()` in this plugin version.
    $page = visit(route('tasks.show', $task));
    $page->resize(375, 812);

    $collapsedHeight = $page->script('document.querySelector(\'[data-testid="composer"]\').getBoundingClientRect().height');
    expect($collapsedHeight)->toBeLessThan(80);

    $page->click('[data-testid="composer-input"]');
    $expandedHeight = $page->script('document.querySelector(\'[data-testid="composer"]\').getBoundingClientRect().height');
    expect($expandedHeight)->toBeGreaterThan($collapsedHeight + 40);
    $page->assertVisible('[data-testid="composer-submit"]');
});
