<?php

use App\Models\Repository;
use App\Models\User;
use App\Models\YakTask;
use Illuminate\Support\Facades\Cache;

function earlyWarningTask(User $requester): YakTask
{
    $repository = Repository::factory()->create(['slug' => 'o-r', 'github_full_name' => 'geocodio/geocodio']);

    return YakTask::factory()->create([
        'repo' => $repository->slug,
        'started_by_user_id' => $requester->id,
        'early_warning' => ['areas' => [['name' => 'API response pipeline', 'risk' => 'high']], 'state' => 'shown'],
    ]);
}

function setAccess(YakTask $task, User $user, string $access): void
{
    Cache::put("co-owner:write-access:{$task->repository->id}:{$user->github_id}", $access, now()->addMinutes(10));
}

it('lets the requester pick a co-owner with write access', function () {
    $requester = User::factory()->create();
    $task = earlyWarningTask($requester);
    $cory = User::factory()->withGitHub(11, 'cory')->create(['name' => 'Cory Mawhorter']);
    setAccess($task, $cory, 'write');
    $this->actingAs($requester);

    visit(route('tasks.show', $task))
        ->assertSee('Heads up: this will likely touch API response pipeline (high risk), so it\'ll need a co-owner before I open a PR.')
        ->assertSee('Only people with write access to geocodio/geocodio can be asked. If someone is missing, they need to link GitHub on their profile first.')
        ->assertSee('Showing people with write access only')
        ->assertSee('Ask later')
        ->click('[data-testid="early-warning-picker"] input[role="combobox"]')
        ->click('text=Cory Mawhorter, write access')
        ->assertSee('Cory Mawhorter will be asked if the change is held. This is a guess based on the request, so it may not be needed.')
        ->assertNoJavascriptErrors();

    expect($task->fresh()->tagged_co_owner_user_id)->toBe($cory->id);
});

it('says no one matches when the typed name has no write access', function () {
    $requester = User::factory()->create();
    $task = earlyWarningTask($requester);
    $cory = User::factory()->withGitHub(11, 'cory')->create(['name' => 'Cory Mawhorter']);
    setAccess($task, $cory, 'write');
    $this->actingAs($requester);

    visit(route('tasks.show', $task))
        ->click('[data-testid="early-warning-picker"] input[role="combobox"]')
        ->type('[data-testid="early-warning-picker"] input[role="combobox"]', 'Zed')
        ->assertSee('No matches')
        ->assertSee('Showing people with write access only')
        ->assertNoJavascriptErrors();
});

it('shows the save failure with a retry when the pick is refused', function () {
    $requester = User::factory()->create();
    $task = earlyWarningTask($requester);
    $cory = User::factory()->withGitHub(11, 'cory')->create(['name' => 'Cory Mawhorter']);
    setAccess($task, $cory, 'write');
    $this->actingAs($requester);

    $page = visit(route('tasks.show', $task))->assertPresent('[data-testid="early-warning-picker"] input[role="combobox"]');
    // The candidate list was cached as writable, then access is lost before the pick is saved.
    setAccess($task, $cory, 'read');

    $page->click('[data-testid="early-warning-picker"] input[role="combobox"]')
        ->click('text=Cory Mawhorter, write access')
        ->assertSee('Could not save your pick.')
        ->assertSee('Cory Mawhorter was not added, and Yak will ask the default co-owner if the change is held. Try again.')
        ->assertSee('Try again')
        // The next poll resets Inertia's page errors, and the alert stays.
        ->wait(16)
        ->assertSee('Could not save your pick.')
        ->assertNoJavascriptErrors();
});

it('loads the picker when the notice arrives through a poll', function () {
    $requester = User::factory()->create();
    $task = earlyWarningTask($requester);
    $task->update(['early_warning' => null]);
    $cory = User::factory()->withGitHub(11, 'cory')->create(['name' => 'Cory Mawhorter']);
    setAccess($task, $cory, 'write');
    $this->actingAs($requester);

    $page = visit(route('tasks.show', $task))->assertNotPresent('[data-testid="early-warning"]');
    $task->update(['early_warning' => ['areas' => [['name' => 'API response pipeline', 'risk' => 'high']], 'state' => 'shown']]);

    $page->wait(16)
        ->assertSee('Heads up: this will likely touch API response pipeline (high risk)')
        ->assertPresent('[data-testid="early-warning-picker"] input[role="combobox"]')
        ->assertDontSee('Checking who has write access')
        ->assertNoJavascriptErrors();
});

it('shows a non-requester the text without the picker', function () {
    $requester = User::factory()->create();
    $task = earlyWarningTask($requester);
    $this->actingAs(User::factory()->create());

    visit(route('tasks.show', $task))
        ->assertSee('Heads up: this will likely touch API response pipeline (high risk)')
        ->assertDontSee('Showing people with write access only')
        ->assertNotPresent('[data-testid="early-warning-picker"]')
        ->assertNoJavascriptErrors();
});

it('shows the answer failure with a retry when the decline is refused', function () {
    $requester = User::factory()->create();
    $task = earlyWarningTask($requester);
    $this->actingAs($requester);

    $page = visit(route('tasks.show', $task))->assertSee('Ask later');
    // The task finishes elsewhere, so the decline is refused as already decided.
    YakTask::whereKey($task->id)->update(['status' => 'success']);

    $page->click('[data-testid="early-warning-decline"]')
        ->assertSee('Could not save your answer.')
        ->assertSee('This request already moved on.')
        ->assertSee('Try again')
        ->assertDontSee('Could not save your pick.')
        ->assertNoJavascriptErrors();
});
