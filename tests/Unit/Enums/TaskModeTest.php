<?php

use App\Enums\TaskMode;

it('has a Review case with value "review"', function () {
    expect(TaskMode::Review->value)->toBe('review');
});

it('exposes all current cases', function () {
    $values = array_map(fn (TaskMode $m) => $m->value, TaskMode::cases());

    expect($values)->toContain('fix', 'research', 'setup', 'review');
});

it('labels the fix mode Change', function () {
    expect(TaskMode::Fix->label())->toBe('Change')
        ->and(TaskMode::Research->label())->toBe('Research')
        ->and(TaskMode::Setup->label())->toBe('Setup')
        ->and(TaskMode::Review->label())->toBe('Review');
});
