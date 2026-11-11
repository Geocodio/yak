<?php

use App\Enums\ThreadVisibility;
use App\Models\Thread;
use App\Models\ThreadShare;
use App\Models\User;
use App\Services\Threads\ThreadSecretFiles;

beforeEach(fn () => $this->withoutVite());

test('the owner reads the share state with secret file names', function () {
    $owner = User::factory()->create();
    $guest = User::factory()->create();
    $thread = Thread::factory()->private($owner)->create();
    ThreadShare::factory()->create(['thread_id' => $thread->id, 'user_id' => $guest->id]);
    $this->mock(ThreadSecretFiles::class)->shouldReceive('for')->andReturn(['files' => ['.env'], 'memoryFolder' => 'memory/', 'memoryFiles' => ['notes.md']]);

    $this->actingAs($owner)->getJson("/threads/{$thread->id}/share")
        ->assertOk()
        ->assertJson([
            'mode' => 'people',
            'userIds' => [$guest->id],
            'people' => [['id' => $guest->id, 'name' => $guest->name]],
            'secretFiles' => ['.env'],
            'memoryFolder' => 'memory/',
            'memoryFiles' => ['notes.md'],
        ]);
});

test('a teammate and an admin get 404 for a private thread, the same as for a missing id', function () {
    $thread = Thread::factory()->private()->create();
    $missing = $this->actingAs(User::factory()->create())->putJson('/threads/999999/share', ['mode' => 'everyone']);

    foreach ([User::factory()->create(), User::factory()->create(['email' => 'admin@example.com'])] as $viewer) {
        $this->actingAs($viewer);
        $this->getJson("/threads/{$thread->id}/share")->assertNotFound();
        $hidden = $this->putJson("/threads/{$thread->id}/share", ['mode' => 'everyone'])->assertNotFound();
        expect(array_keys($hidden->json()))->toBe(array_keys($missing->json()));
    }

    expect($thread->fresh()->visibility)->toBe(ThreadVisibility::Private);
});

test('a shared person gets 403 on update', function () {
    $guest = User::factory()->create();
    $thread = Thread::factory()->private()->create();
    ThreadShare::factory()->create(['thread_id' => $thread->id, 'user_id' => $guest->id]);

    $this->actingAs($guest);
    $this->putJson("/threads/{$thread->id}/share", ['mode' => 'everyone'])->assertForbidden();
    $this->getJson("/threads/{$thread->id}/share")->assertForbidden();
});

test('a viewer of a team thread without being its owner gets 403', function () {
    $thread = Thread::factory()->create(['owner_user_id' => User::factory()->create()->id]);
    $nobody = Thread::factory()->create();

    $this->actingAs(User::factory()->create());
    $this->putJson("/threads/{$thread->id}/share", ['mode' => 'only_me'])->assertForbidden();
    $this->putJson("/threads/{$nobody->id}/share", ['mode' => 'only_me'])->assertForbidden();
});

test('the owner switches only_me to people to everyone and back', function () {
    $owner = User::factory()->create();
    $guest = User::factory()->create();
    $thread = Thread::factory()->private($owner)->create();
    $this->actingAs($owner);

    $this->putJson("/threads/{$thread->id}/share", ['mode' => 'people', 'user_ids' => [$guest->id]])
        ->assertOk()->assertExactJson(['mode' => 'people', 'userIds' => [$guest->id]]);
    $this->putJson("/threads/{$thread->id}/share", ['mode' => 'everyone'])
        ->assertOk()->assertExactJson(['mode' => 'everyone', 'userIds' => []]);
    expect($thread->fresh()->visibility)->toBe(ThreadVisibility::Team);
    $this->putJson("/threads/{$thread->id}/share", ['mode' => 'only_me'])
        ->assertOk()->assertExactJson(['mode' => 'only_me', 'userIds' => []]);
    expect($thread->fresh()->visibility)->toBe(ThreadVisibility::Private);
});

test('people mode with a deleted or own id ignores them', function () {
    $owner = User::factory()->create();
    $thread = Thread::factory()->private($owner)->create();

    $this->actingAs($owner)->putJson("/threads/{$thread->id}/share", ['mode' => 'people', 'user_ids' => [$owner->id, 999999]])
        ->assertOk()->assertExactJson(['mode' => 'only_me', 'userIds' => []]);
});

test('people mode with no ids is a 422', function () {
    $owner = User::factory()->create();
    $thread = Thread::factory()->private($owner)->create();
    $this->actingAs($owner);

    $this->putJson("/threads/{$thread->id}/share", ['mode' => 'people'])->assertUnprocessable();
    $this->putJson("/threads/{$thread->id}/share", ['mode' => 'nope'])->assertUnprocessable();
});

test('sharing a child shares its root, and the owner of a team thread can make it private', function () {
    $owner = User::factory()->create();
    $root = Thread::factory()->create(['owner_user_id' => $owner->id]);
    $child = Thread::factory()->create(['parent_thread_id' => $root->id, 'root_thread_id' => $root->id]);

    $this->actingAs($owner)->putJson("/threads/{$child->id}/share", ['mode' => 'only_me'])->assertOk();

    expect($root->fresh()->visibility)->toBe(ThreadVisibility::Private);
});
