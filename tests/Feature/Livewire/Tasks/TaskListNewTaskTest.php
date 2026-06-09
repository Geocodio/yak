<?php

use App\Livewire\Tasks\TaskList;
use Livewire\Livewire;

test('task list shows the New task trigger and embeds the CreateTask component', function () {
    Livewire::test(TaskList::class)
        ->assertSeeHtml('data-testid="new-task-trigger"')
        ->assertSeeHtml('data-testid="create-task-form"');
});
