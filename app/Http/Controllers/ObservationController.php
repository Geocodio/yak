<?php

namespace App\Http\Controllers;

use App\Http\Resources\ObservationData;
use App\Models\Observation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Inertia\Inertia;
use Inertia\Response;

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
                    'repos' => $this->distinctRepos(),
                ],
            ],
        ]);
    }

    /**
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    private function paginated(string $repo, string $outcome): LengthAwarePaginator
    {
        /** @var LengthAwarePaginator<int, Observation> $observations */
        $observations = Observation::query()
            ->when($repo !== '', fn (Builder $query) => $query->where('repo', $repo))
            ->when(
                in_array($outcome, [Observation::OUTCOME_ACTED, Observation::OUTCOME_DECLINED], true),
                fn (Builder $query) => $query->where('outcome', $outcome),
            )
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(50)
            ->withQueryString();

        /** @var LengthAwarePaginator<int, array<string, mixed>> $mapped */
        $mapped = $observations->through(ObservationData::from(...));

        return $mapped;
    }

    /**
     * @return array<int, string>
     */
    private function distinctRepos(): array
    {
        return Observation::query()
            ->whereNotNull('repo')
            ->distinct()
            ->pluck('repo')
            ->sort()
            ->values()
            ->all();
    }
}
