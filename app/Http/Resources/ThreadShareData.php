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
     * @return array{mode: string, userIds: list<int>, people: list<array{id: int, name: string, role: ?string}>, secretFiles: mixed, memoryFolder: mixed, memoryFiles: mixed}
     */
    public static function for(Thread $thread, User $owner): array
    {
        $current = app(ThreadSharing::class)->current($thread);
        $secrets = app(ThreadSecretFiles::class)->for($thread->rootThread());

        return [
            'mode' => $current['mode']->value,
            'userIds' => $current['userIds'],
            'people' => User::query()->orderBy('name')->get(['id', 'name'])
                ->map(fn (User $user): array => ['id' => $user->id, 'name' => $user->name, 'role' => $user->id === $owner->id ? 'Owner' : null])->values()->all(),
            'secretFiles' => $secrets['files'],
            'memoryFolder' => $secrets['memoryFolder'],
            'memoryFiles' => $secrets['memoryFiles'],
        ];
    }
}
