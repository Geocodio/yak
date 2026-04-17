<?php

use App\Enums\TaskMode;

it('has a Review case with value "review"', function () {
    expect(TaskMode::Review->value)->toBe('review');
});

it('exposes all current cases', function () {
    $values = array_map(fn (TaskMode $m) => $m->value, TaskMode::cases());

    expect($values)->toContain('fix', 'research', 'setup', 'review');
});
