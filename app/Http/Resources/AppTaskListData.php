<?php

namespace App\Http\Resources;

use App\DataTransferObjects\TaskListFilters;
use App\Services\Tasks\TaskListQuery;

/**
 * One page of the task list for the app.
 */
final class AppTaskListData
{
    /**
     * @return array{tasks: list<array<string, mixed>>, nextPage: ?int, counts: array{tasks: int, reviews: int, setup: int, needsCoOwner: int}, options: array{repos: list<string>, sources: list<string>}}
     */
    public static function for(TaskListFilters $filters, int $page): array
    {
        $query = app(TaskListQuery::class);
        $rows = $query->rows($filters, $page);

        return [
            'tasks' => array_values($rows->items()),
            'nextPage' => $rows->hasMorePages() ? $page + 1 : null,
            'counts' => $query->counts(),
            'options' => $query->options(),
        ];
    }
}
