<?php

namespace App\Http\Resources;

use App\Models\Thread;
use App\Models\User;
use App\Services\Threads\ThreadSecretFiles;
use App\Services\Threads\ThreadSharing;

class ThreadShareData
{
    /**
     * The sharing page body, shared by the web and the app.
     *
     * @return array{mode: string, userIds: list<int>, people: list<array{id: int, name: string, role: ?string}>, secretFiles: list<string>, memoryFolder: ?string, memoryFiles: list<string>}
     */
    public static function for(Thread $thread): array
    {
        $current = app(ThreadSharing::class)->current($thread);
        $ownerId = $thread->effectiveOwnerUserId();
        $secrets = app(ThreadSecretFiles::class)->for($thread->rootThread());

        return [
            'mode' => $current['mode']->value,
            'userIds' => $current['userIds'],
            'people' => User::query()->orderBy('name')->get(['id', 'name'])
                ->map(fn (User $user): array => ['id' => $user->id, 'name' => $user->name, 'role' => $user->id === $ownerId ? 'Owner' : null])->values()->all(),
            'secretFiles' => $secrets['files'],
            'memoryFolder' => $secrets['memoryFolder'],
            'memoryFiles' => $secrets['memoryFiles'],
        ];
    }
}
