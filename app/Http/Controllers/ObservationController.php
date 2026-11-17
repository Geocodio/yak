<?php

namespace App\Http\Controllers;

use App\Http\Resources\ObservationData;
use App\Services\ObservationQuery;
use App\Support\TaskPageUrl;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Inertia\Inertia;
use Inertia\Response;
use Inertia\ScrollProp;

class ObservationController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $repo = $request->string('repo')->toString();
        $outcome = $request->string('outcome')->toString();

        return Inertia::render('Observations/Index', [
            'observations' => fn () => $this->paginated($repo, $outcome),
            'filters' => [
                'repo' => $repo,
                'outcome' => $outcome,
                'options' => fn () => [
                    'repos' => ObservationQuery::repos(),
                ],
            ],
        ]);
    }

    /**
     * @return ScrollProp<LengthAwarePaginator<int, array<string, mixed>>>
     */
    private function paginated(string $repo, string $outcome): ScrollProp
    {
        /** @var LengthAwarePaginator<int, array<string, mixed>> $mapped */
        $page = ObservationQuery::page($repo, $outcome);
        $taskUrls = TaskPageUrl::forVisibleTaskIds($page->getCollection()->pluck('yak_task_id')->filter()->values()->all());
        $mapped = $page->through(fn ($observation): array => ObservationData::from($observation, $taskUrls));

        return Inertia::scroll($mapped)->matchOn('data.id');
    }
}
