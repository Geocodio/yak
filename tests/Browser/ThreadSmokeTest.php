<?php

use App\Models\Repository;
use App\Models\Thread;
use App\Models\User;
use App\Services\Devices\DeviceFlow;

it('loads every new page in light and dark without console or JavaScript errors', function (string $colorScheme) {
    $user = User::factory()->create();
    $thread = Thread::factory()->create();
    $repository = Repository::factory()->create();
    ['authorization' => $authorization] = app(DeviceFlow::class)->start(['name' => 'work-mac', 'os' => 'darwin', 'arch' => 'arm64', 'cli_version' => '0.4.2'], null);
    $this->actingAs($user);

    $urls = [
        route('threads.list'),
        route('threads.new'),
        route('threads.show', $thread),
        route('settings.claude-accounts'),
        route('settings.devices'),
        route('settings.notifications'),
        route('settings.ssh-keys'),
        route('repos.edit', $repository),
        '/device?code=' . $authorization->user_code,
    ];

    $pages = visit($urls);
    $pages = $colorScheme === 'dark' ? $pages->inDarkMode() : $pages->inLightMode();

    $pages->assertNoSmoke();
})->with(['light', 'dark']);
