<?php

namespace App\Http\Controllers\Tasks;

use App\Http\Controllers\Controller;
use App\Models\Repository;
use App\Models\YakTask;
use App\Support\TaskPageUrl;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Old task links keep working for good: each one answers 301 to the page the
 * task lives on now. A task the viewer may not see answers 404, like an
 * unknown one.
 */
class TaskRedirectController extends Controller
{
    public function show(Request $request, string $task): RedirectResponse
    {
        $found = YakTask::query()
            ->where(ctype_digit($task) ? 'id' : 'public_id', $task)
            ->first() ?? abort(404);

        $url = TaskPageUrl::for($found, null, $request->only(['t', 'repo', 'log'])) ?? abort(404);

        return redirect()->to($url, 301);
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
