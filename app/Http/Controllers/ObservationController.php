<?php

namespace App\Http\Controllers;

use App\Http\Resources\ObservationData;
use App\Services\ObservationQuery;
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
            'observations' => fn () => $this->paginated($repo, $outcome, max(1, $request->integer('page', 1))),
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
    private function paginated(string $repo, string $outcome, int $page): ScrollProp
    {
        /** @var LengthAwarePaginator<int, array<string, mixed>> $mapped */
        $mapped = ObservationQuery::page($repo, $outcome, $page)->through(ObservationData::from(...));

        return Inertia::scroll($mapped)->matchOn('data.id');
    }
}
