<?php

use App\Models\User;
use App\Models\YakTask;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->task = YakTask::factory()->create([
        'source' => 'email',
        'repo' => 'unknown',
        'status' => 'awaiting_clarification',
        'clarification_options' => ['geocodio', 'geocodio-website'],
        'started_by_user_id' => $this->user->id,
    ]);
    $this->actingAs($this->user);
});

it('fills the composer with the repo picked in the auto-reply', function () {
    visit(route('tasks.show', ['task' => $this->task, 'repo' => 'geocodio-website']))
        ->assertValue('[data-testid="composer-input"]', 'geocodio-website')
        ->assertNoJavascriptErrors();
});

it('ignores a repo that is not one of the task\'s options', function () {
    visit(route('tasks.show', ['task' => $this->task, 'repo' => 'evil']))
        ->assertValue('[data-testid="composer-input"]', '')
        ->assertNoJavascriptErrors();
});
