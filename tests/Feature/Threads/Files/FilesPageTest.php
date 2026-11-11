<?php

use App\Models\Thread;
use App\Models\ThreadShare;
use App\Models\User;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(fn () => $this->withoutVite());

/*
 * The panel is a client render, so its behaviour is covered by the endpoint tests (FileSaveTest,
 * HumanEditNotesTest); journey 14 lives in tests/Browser/FilesEditorTest.php.
 */

it('tells the page whether the person can drive, which decides if the editor is writable', function () {
    $owner = User::factory()->create();
    $viewer = User::factory()->create();
    $thread = Thread::factory()->private($owner)->create();
    ThreadShare::factory()->create(['thread_id' => $thread->id, 'user_id' => $viewer->id]);

    $this->actingAs($owner)->get(route('threads.show', $thread))
        ->assertInertia(fn (Assert $page) => $page->where('canDrive', true));

    $this->actingAs($viewer)->get(route('threads.show', $thread))
        ->assertInertia(fn (Assert $page) => $page->where('canDrive', false));
});

it('registers the file routes under their names', function (string $name) {
    expect(Route::has($name))->toBeTrue();
})->with(['threads.files.tree', 'threads.files.show', 'threads.files.update']);
