<?php

use App\Models\Thread;
use App\Models\User;
use App\Services\Push\PushEvent;
use App\Services\Push\ThreadPresence;
use App\Services\Push\ThreadPush;
use Tests\Support\FakePushEndpoint;

it('never pushes a private thread to anyone but its owner', function () {
    $push = FakePushEndpoint::install();
    $owner = User::factory()->create();
    $stranger = User::factory()->create();
    $admin = User::factory()->create();
    $thread = Thread::factory()->private($owner)->create(['title' => 'Secret migration plan']);
    $push->subscription($stranger);
    $push->subscription($admin);
    $device = $push->subscription($owner);
    $push->respondWith();

    $sent = app(ThreadPush::class)->send(PushEvent::NeedsYou, $thread, [$stranger, $admin, $owner], 'Secret body');

    expect($sent)->toBe(1)
        ->and($push->requests())->toHaveCount(1);
    $push->assertSentTo($device->endpoint);
    expect((string) $push->requests()[0]->getBody())->not->toContain('Secret');
});

it('only reaches the owner of a private thread for every event kind and never asks presence about others', function (PushEvent $event) {
    $push = FakePushEndpoint::install();
    $owner = User::factory()->create();
    $teammate = User::factory()->create();
    $admin = User::factory()->create();
    $thread = Thread::factory()->private($owner)->create(['title' => 'Secret migration plan']);
    foreach ([$teammate, $admin, $owner] as $person) {
        $push->subscription($person);
    }
    $push->respondWith();
    $this->mock(ThreadPresence::class)->shouldReceive('isViewing')->once()->withArgs(fn (User $user) => $user->is($owner))->andReturnFalse();

    $sent = app(ThreadPush::class)->send($event, $thread, [$teammate, $admin, $owner], 'Secret migration plan is ready');

    expect($sent)->toBe(1)->and($push->requests())->toHaveCount(1);
    expect((string) $push->requests()[0]->getBody())->not->toContain('Secret');
})->with(PushEvent::cases());

it('keeps the webpush channel behind app/Services/Push', function () {
    $offenders = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path(), FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->getExtension() !== 'php' || str_contains($file->getPathname(), '/Services/Push/')) {
            continue;
        }

        if (preg_match('/WebPushChannel|WebPushMessage/', file_get_contents($file->getPathname()))) {
            $offenders[] = $file->getPathname();
        }
    }

    expect($offenders)->toBe([]);
});
