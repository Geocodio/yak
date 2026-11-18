<?php

namespace App\Http\Controllers\Tasks;

use App\Enums\TaskMode;
use App\Enums\TaskStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\RepositoryRunData;
use App\Models\Repository;
use App\Models\YakTask;
use App\Services\Threads\ThreadFactory;
use App\Support\TaskPageUrl;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Old task links keep working for good: each one answers 301 to the page the
 * task lives on now. A task the viewer may not see answers 404, like an
 * unknown one. A fix or research conversation that began before threads
 * existed and is still live has no thread until its next run, so opening the
 * link of one that waits on a person gives the conversation its thread first
 * (a finished or running one without a thread stays without).
 * Reviews, setup runs and repository research never get one.
 */
class TaskRedirectController extends Controller
{
    public function show(Request $request, string $task): RedirectResponse
    {
        $found = YakTask::query()
            ->where(ctype_digit($task) ? 'id' : 'public_id', $task)
            ->first() ?? abort(404);

        $this->giveWaitingConversationItsThread($found);

        $url = TaskPageUrl::for($found, null, $request->only(['t', 'repo', 'log'])) ?? abort(404);

        return redirect()->to($url, 301);
    }

    private function giveWaitingConversationItsThread(YakTask $found): void
    {
        if ($found->mode === TaskMode::Review || RepositoryRunData::isRepositoryRun($found) || TaskPageUrl::for($found) !== null) {
            return;
        }

        $root = $found;

        while ($root->parent_task_id !== null) {
            $root = YakTask::query()->withoutGlobalScopes()->find($root->parent_task_id) ?? $root;

            if ($root->id === $found->id || $root->parent_task_id === null) {
                break;
            }
        }

        $members = collect([$root]);
        $frontier = [$root->id];

        while ($frontier !== []) {
            $children = YakTask::query()->withoutGlobalScopes()->whereIn('parent_task_id', $frontier)->get();
            $members = $members->concat($children);
            $frontier = $children->pluck('id')->all();
        }

        $waitsOnPerson = fn (YakTask $task): bool => in_array($task->status, [TaskStatus::AwaitingClarification, TaskStatus::AwaitingCoOwner], true);
        $memberIds = $members->pluck('id')->all();

        if ($members->contains(fn (YakTask $member): bool => $member->thread_id !== null)
            || ! ($waitsOnPerson($found) || $waitsOnPerson($root))
            || YakTask::query()->whereIn('id', $memberIds)->count() !== count($memberIds)) {
            return;
        }

        $thread = app(ThreadFactory::class)->forTask($root);

        $members->each(fn (YakTask $member) => $member->withoutTimestamps(fn () => $member->forceFill(['thread_id' => $thread->id])->save()));

        $found->setAttribute('thread_id', $thread->id);
    }

    public function index(Request $request): RedirectResponse
    {
        $tab = $request->query('tab');
        $repo = $request->query('repo');
        $repo = is_string($repo) ? $repo : '';

        if ($tab === 'reviews') {
            return redirect()->route('pr-reviews', [], 301);
        }

        if ($tab === 'setup') {
            $repository = $repo === '' ? null : Repository::query()->where('slug', $repo)->first();

            return $repository === null
                ? redirect()->route('repos', [], 301)
                : redirect()->route('repos.runs.index', ['repository' => $repository, 'kind' => 'setup'], 301);
        }

        return redirect()->route('threads.log', array_filter(['repo' => $repo]), 301);
    }
}
