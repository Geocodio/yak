<?php

namespace App\Http\Controllers\Tasks;

use App\DataTransferObjects\TaskListFilters;
use App\Http\Controllers\Controller;
use App\Services\Tasks\TaskListQuery;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;
use Inertia\Inertia;
use Inertia\Response;

class TaskListController extends Controller
{
    public function __invoke(Request $request, TaskListQuery $query): Response
    {
        $filters = TaskListFilters::fromLenientRequest($request);

        return Inertia::render('Tasks/Index', [
            'tasks' => fn () => Inertia::scroll($query->rows($filters, Paginator::resolveCurrentPage()))->matchOn('data.id'),
            'counts' => fn () => $query->counts(),
            'filters' => [
                'status' => $filters->status ?? '',
                'source' => $filters->source ?? '',
                'repo' => $filters->repo ?? '',
                'pr' => $filters->pr ?? '',
                'sort' => $request->string('sort', 'created_at')->toString(),
                'direction' => $filters->direction,
                'tab' => $filters->tab,
                'options' => fn () => $query->options(),
            ],
        ]);
    }
}
