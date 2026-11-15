<?php

namespace App\DataTransferObjects;

use Illuminate\Http\Request;

/**
 * The task list's filters. The web builds them leniently (unknown values fall back to the defaults);
 * the app validates them first and builds them from the validated values.
 */
final readonly class TaskListFilters
{
    /** @var list<string> */
    public const array SORTABLE_COLUMNS = ['status', 'source', 'author_name', 'repo', 'created_at'];

    public function __construct(
        public string $tab = 'tasks',
        public ?string $status = null,
        public ?string $source = null,
        public ?string $repo = null,
        public ?string $pr = null,
        public string $sort = 'created_at',
        public string $direction = 'desc',
        public ?string $search = null,
    ) {}

    public static function fromLenientRequest(Request $request): self
    {
        $sort = $request->string('sort', 'created_at')->toString();

        return new self(
            tab: $request->string('tab', 'tasks')->toString(),
            status: self::blankToNull($request->string('status')->toString()),
            source: self::blankToNull($request->string('source')->toString()),
            repo: self::blankToNull($request->string('repo')->toString()),
            pr: self::blankToNull($request->string('pr')->toString()),
            sort: in_array($sort, self::SORTABLE_COLUMNS, true) ? $sort : 'created_at',
            direction: $request->string('direction', 'desc')->toString() === 'asc' ? 'asc' : 'desc',
        );
    }

    private static function blankToNull(string $value): ?string
    {
        return $value === '' ? null : $value;
    }
}
