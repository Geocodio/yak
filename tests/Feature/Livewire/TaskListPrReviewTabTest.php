<?php

use App\Enums\TaskMode;
use App\Livewire\Tasks\TaskList;
use App\Models\User;
use App\Models\YakTask;
use Livewire\Livewire;

it('filters tasks by review mode when reviews tab is active', function () {
    $user = User::factory()->create();

    YakTask::factory()->count(2)->create(['mode' => TaskMode::Review]);
    YakTask::factory()->count(3)->create(['mode' => TaskMode::Fix]);

    $component = Livewire::actingAs($user)
        ->test(TaskList::class)
        ->set('tab', 'reviews');

    expect($component->instance()->tasks()->count())->toBe(2);
});

it('reports review count', function () {
    $user = User::factory()->create();

    YakTask::factory()->count(4)->create(['mode' => TaskMode::Review]);

    $component = Livewire::actingAs($user)->test(TaskList::class);

    expect($component->instance()->reviewsCount())->toBe(4);
});
