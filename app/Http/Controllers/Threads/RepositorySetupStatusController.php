<?php

namespace App\Http\Controllers\Threads;

use App\Http\Controllers\Controller;
use App\Models\Repository;
use App\Services\Threads\Repos\RepositorySetupProgress;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Setup status of one repository, polled by the start screen's status strip.
 */
class RepositorySetupStatusController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $slug = $request->validate(['repo' => ['required', 'string', 'max:255']])['repo'];

        return response()->json(RepositorySetupProgress::for(Repository::query()->where('slug', $slug)->where('is_active', true)->firstOrFail()));
    }
}
