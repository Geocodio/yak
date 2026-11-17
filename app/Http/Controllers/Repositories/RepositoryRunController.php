<?php

namespace App\Http\Controllers\Repositories;

use App\Enums\TaskMode;
use App\Exceptions\ThreadActionRefused;
use App\Http\Controllers\Controller;
use App\Http\Resources\RepositoryRunData;
use App\Http\Resources\RepositorySettingsData;
use App\Http\Resources\ThreadRunLogData;
use App\Models\Repository;
use App\Models\YakTask;
use App\Services\ClaudeAccounts\ClaudeModelCatalog;
use App\Services\Tasks\TaskActions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * The Runs tab: the setup runs and repository research runs of a repository, with one run open in full.
 */
class RepositoryRunController extends Controller
{
    public function show(Request $request, Repository $repository, ?YakTask $task = null): Response
    {
        abort_if($task !== null && ! RepositoryRunData::belongsToRepository($task, $repository), 404);

        $filters = ['kind' => $this->filter($request, 'kind'), 'status' => $this->filter($request, 'status')];
        $built = null;
        $page = function () use (&$built, $repository, $filters, $task): array {
            if ($built !== null) {
                return $built;
            }

            $list = RepositoryRunData::list($repository, $filters);
            $selected = $task ?? ($list['rows'] === [] ? null : YakTask::query()->where('public_id', $list['rows'][0]['id'])->first());

            return $built = [...$list, 'selected' => $selected === null ? null : RepositoryRunData::detail($selected, $repository)];
        };

        return Inertia::render('Repositories/Runs', [
            'header' => fn () => RepositorySettingsData::header($repository),
            'runs' => fn () => $page()['rows'],
            'totalCount' => fn () => $page()['totalCount'],
            'selected' => fn () => $page()['selected'],
            'filters' => $filters,
            'isRunOpen' => $task !== null,
            'modelLabels' => fn () => app(ClaudeModelCatalog::class)->labels(),
            'rerunSetupUrl' => route('repos.rerun-setup', $repository),
            'researchUrl' => route('repos.risk-profile', $repository),
            'settingsUrl' => RepositorySettingsData::sectionUrl($repository, 'sandbox'),
            'listUrl' => route('repos.runs.index', ['repository' => $repository, ...array_filter($filters)]),
            'canResearch' => (bool) $repository->is_active,
            ...RepositorySettingsData::yakConfigProp($repository),
        ]);
    }

    public function log(Request $request, Repository $repository, YakTask $task): JsonResponse
    {
        abort_unless(RepositoryRunData::belongsToRepository($task, $repository), 404);

        $search = $request->query('search');

        return response()->json(ThreadRunLogData::forTask(
            $task,
            max(0, $request->integer('cursor')),
            is_string($search) && trim($search) !== '' ? mb_substr(trim($search), 0, 200) : null,
        ));
    }

    public function retry(Repository $repository, YakTask $task, TaskActions $actions): RedirectResponse
    {
        abort_unless(RepositoryRunData::belongsToRepository($task, $repository) && $task->mode === TaskMode::Setup, 404);

        $back = redirect()->route('repos.runs.show', [$repository, $task]);

        try {
            $outcome = $actions->retry($task);
        } catch (ThreadActionRefused|HttpException $refused) {
            if ($refused instanceof HttpException && $refused->getStatusCode() !== 503) {
                throw $refused;
            }

            return $back->with('error', $refused->getMessage());
        }

        return $outcome->sentence === '' ? $back : $back->with('success', $outcome->sentence);
    }

    private function filter(Request $request, string $key): ?string
    {
        $value = $request->query($key);

        return is_string($value) && $value !== '' ? mb_substr($value, 0, 40) : null;
    }
}
