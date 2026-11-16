<?php

namespace App\Http\Controllers\Cli;

use App\Http\Controllers\Controller;
use App\Http\Requests\Cli\ResolveRepositoriesRequest;
use App\Services\Devices\RepositoryResolver;
use Illuminate\Http\JsonResponse;

class RepositoryResolveController extends Controller
{
    public function __invoke(ResolveRepositoriesRequest $request, RepositoryResolver $repositoryResolver): JsonResponse
    {
        /** @var list<string> $remotes */
        $remotes = array_values($request->validated('remotes'));

        $repos = array_map(function (string $remote) use ($repositoryResolver): array {
            $repository = $repositoryResolver->resolve($remote);

            return [
                'remote' => $remote,
                'on_yak' => $repository !== null,
                'repo' => $repository?->slug,
                'default_branch' => $repository?->default_branch,
                'clone_url' => $repository !== null ? 'https://github.com/' . $repository->github_full_name . '.git' : null,
            ];
        }, $remotes);

        return response()->json(['repos' => $repos]);
    }
}
