<?php

namespace App\Http\Controllers\Api\App;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\App\ListAppObservationsRequest;
use App\Http\Resources\AppObservationData;
use App\Models\YakTask;
use App\Services\ObservationQuery;
use Illuminate\Http\JsonResponse;

class ObservationController extends Controller
{
    public function __invoke(ListAppObservationsRequest $request): JsonResponse
    {
        $page = $request->page();
        $rows = ObservationQuery::page($request->validated('repo'), $request->validated('outcome'), $page, $request->validated('search'));

        $taskKeys = $rows->getCollection()->pluck('yak_task_id')->filter()->unique()->values()->all();
        /** @var array<int, string> $visibleTaskPublicIds */
        $visibleTaskPublicIds = $taskKeys === []
            ? []
            : YakTask::query()->whereIn('id', $taskKeys)->pluck('public_id', 'id')->all();

        return response()->json([
            'observations' => $rows->getCollection()->map(fn ($observation): array => AppObservationData::from($observation, $visibleTaskPublicIds))->values()->all(),
            'nextPage' => $rows->hasMorePages() ? $page + 1 : null,
            'options' => ['repos' => ObservationQuery::repos()],
        ]);
    }
}
