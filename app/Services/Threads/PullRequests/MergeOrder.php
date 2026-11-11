<?php

namespace App\Services\Threads\PullRequests;

use App\Jobs\Threads\RefreshSiblingBlocksJob;
use App\Models\Thread;
use App\Models\ThreadRepository;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The suggested order to merge a thread's pull requests. Informational only.
 */
class MergeOrder
{
    /**
     * @return Collection<int, ThreadRepository>
     */
    public function forThread(Thread $thread): Collection
    {
        return ThreadRepository::query()
            ->where('thread_id', $thread->id)
            ->where('role', '!=', 'context')
            ->whereNotNull('pull_request_url')
            ->orderByRaw('merge_position is null, merge_position')
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  list<int>  $orderedRowIds
     *
     * @throws ValidationException
     */
    public function set(Thread $thread, array $orderedRowIds): void
    {
        $expected = $this->forThread($thread)->pluck('id')->all();

        if (count($orderedRowIds) !== count($expected) || array_diff($orderedRowIds, $expected) !== [] || count(array_unique($orderedRowIds)) !== count($orderedRowIds)) {
            throw ValidationException::withMessages(['order' => 'The order must list each pull request of this thread exactly once.']);
        }

        DB::transaction(function () use ($orderedRowIds): void {
            foreach ($orderedRowIds as $index => $rowId) {
                ThreadRepository::query()->whereKey($rowId)->update(['merge_position' => $index + 1]);
            }
        });

        RefreshSiblingBlocksJob::dispatch($thread->id);
    }

    /**
     * Rows without a position take the next ones, in the order they opened.
     */
    public function assignDefault(Thread $thread): void
    {
        $rows = $this->forThread($thread);
        $next = (int) $rows->max('merge_position');

        foreach ($rows->whereNull('merge_position')->sortBy('id') as $row) {
            $row->forceFill(['merge_position' => ++$next])->save();
        }
    }
}
