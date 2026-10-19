<?php

namespace App\Http\Controllers\Repositories;

use App\Http\Controllers\Controller;
use App\Http\Resources\RiskProfilePageData;
use App\Models\Repository;
use App\Services\RepositoryConfig;
use App\Services\RiskProfileChangeCheck;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The read-only risk profile page. Nothing here writes to GitHub or the database.
 */
class RiskProfileController extends Controller
{
    public function __invoke(Request $request, Repository $repository): Response
    {
        return Inertia::render('Repositories/RiskProfile', [
            'repository' => fn (): array => [
                'slug' => $repository->slug,
                'name' => $repository->name,
                'defaultBranch' => $repository->default_branch,
                'githubUrl' => $repository->githubUrl(),
                'editUrl' => route('repos.edit', $repository),
                'generateUrl' => route('repos.risk-profile', $repository),
            ],
            'riskProfile' => Inertia::defer(
                fn (): array => RiskProfilePageData::from($repository, app(RepositoryConfig::class)->snapshot($repository)),
                'risk-profile',
            ),
            'changeTest' => Inertia::optional(fn (): ?array => $request->filled('change')
                ? app(RiskProfileChangeCheck::class)->run($repository, (string) $request->query('change'))
                : null),
        ]);
    }
}
