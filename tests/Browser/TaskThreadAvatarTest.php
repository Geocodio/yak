<?php

use App\Models\User;
use App\Models\YakTask;

test('yak thread entries show the walker avatar', function () {
    $this->actingAs(User::factory()->create());

    $task = YakTask::factory()->running()->create([
        'description' => 'Fix the flaky checkout test',
    ]);

    visit(route('tasks.show', $task))
        ->assertSee('Fix the flaky checkout test')
        ->assertVisible('img[src="/mascot-avatar.png"]');
});
