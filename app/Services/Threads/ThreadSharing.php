<?php

namespace App\Services\Threads;

use App\Enums\ThreadShareMode;
use App\Enums\ThreadVisibility;
use App\Models\Thread;
use App\Models\ThreadShare;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Changes who may see a thread. Only the root thread's owner may share, a
 * child resolves to its root, and every change runs in one transaction on the
 * locked root row so a failure leaves visibility and share rows untouched.
 */
class ThreadSharing
{
    /**
     * @param  list<int>  $userIds
     *
     * @throws AuthorizationException
     */
    public function apply(Thread $thread, User $actor, ThreadShareMode $mode, array $userIds = []): void
    {
        DB::transaction(function () use ($thread, $actor, $mode, $userIds): void {
            $root = $this->lockedRoot($thread);

            if ($root->owner_user_id !== $actor->id) {
                throw new AuthorizationException('Only the owner can share a thread.');
            }

            $root->visibility = $mode === ThreadShareMode::Everyone ? ThreadVisibility::Team : ThreadVisibility::Private;
            $root->save();

            $wanted = $mode === ThreadShareMode::People
                ? array_values(User::query()->whereKey($userIds)->whereKeyNot($actor->id)->pluck('id')->all())
                : [];

            ThreadShare::query()->where('thread_id', $root->id)->whereNotIn('user_id', $wanted)->delete();

            foreach ($wanted as $userId) {
                ThreadShare::query()->firstOrCreate(['thread_id' => $root->id, 'user_id' => $userId]);
            }
        });
    }

    /**
     * @return array{mode: ThreadShareMode, userIds: list<int>}
     */
    public function current(Thread $thread): array
    {
        $root = Thread::query()->withoutGlobalScopes()->findOrFail($thread->root_thread_id ?? $thread->id);

        if ($root->visibility === ThreadVisibility::Team) {
            return ['mode' => ThreadShareMode::Everyone, 'userIds' => []];
        }

        $userIds = array_values(ThreadShare::query()->where('thread_id', $root->id)->orderBy('user_id')->pluck('user_id')
            ->map(fn ($id): int => (int) $id)->all());

        return ['mode' => $userIds === [] ? ThreadShareMode::OnlyMe : ThreadShareMode::People, 'userIds' => $userIds];
    }

    private function lockedRoot(Thread $thread): Thread
    {
        return Thread::query()->withoutGlobalScopes()->lockForUpdate()
            ->findOrFail($thread->root_thread_id ?? $thread->id);
    }
}
