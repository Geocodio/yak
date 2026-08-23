<?php

use Illuminate\Support\Facades\Schema;

test('tasks table has parent_task_id and pr_number columns', function () {
    expect(Schema::hasColumn('tasks', 'parent_task_id'))->toBeTrue()
        ->and(Schema::hasColumn('tasks', 'pr_number'))->toBeTrue();
});
