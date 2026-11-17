<?php

namespace App\Http\Controllers\Threads;

use App\Http\Controllers\Controller;
use App\Http\Requests\Threads\ThreadLogRequest;
use App\Http\Resources\ThreadLogRowData;
use App\Models\User;
use App\Services\Threads\ThreadLogQuery;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Inertia\Inertia;
use Inertia\Response;

class ThreadLogController extends Controller
{
    private const int PER_PAGE = 50;

    public function __invoke(ThreadLogRequest $request): Response
    {
        /** @var User $viewer */
        $viewer = $request->user();
        $filters = $request->filters();
        $paginator = null;
        $page = function () use (&$paginator, $viewer, $filters): LengthAwarePaginator {
            return $paginator ??= ThreadLogQuery::for($viewer, $filters)
                ->paginate(self::PER_PAGE, ['threads.*'], 'page', Paginator::resolveCurrentPage());
        };

        return Inertia::render('Threads/Log', [
            'rows' => fn () => Inertia::scroll(tap($page(), fn (LengthAwarePaginator $threads) => $threads->setCollection(
                collect(ThreadLogRowData::forPage($threads->getCollection()))
            )))->matchOn('data.id'),
            'counts' => fn () => ['total' => $page()->total(), 'needsCoOwner' => ThreadLogQuery::needsCoOwnerCount($viewer)],
            'filters' => $filters,
            'facets' => fn () => ThreadLogQuery::facets($viewer, $filters),
        ]);
    }
}
