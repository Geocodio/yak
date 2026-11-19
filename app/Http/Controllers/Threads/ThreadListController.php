<?php

namespace App\Http\Controllers\Threads;

use App\Enums\ThreadListFilter;
use App\Http\Controllers\Controller;
use App\Services\Threads\ThreadList;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ThreadListController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'filter' => ['nullable', Rule::enum(ThreadListFilter::class)],
            'sources' => ['nullable', 'array', 'max:50'],
            'sources.*' => ['string', 'max:64'],
            'starters' => ['nullable', 'array', 'max:50'],
            'starters.*' => ['string', 'max:255'],
        ]);

        return response()->json(ThreadList::for(
            $request->user() ?? abort(401),
            ThreadListFilter::tryFrom($validated['filter'] ?? '') ?? ThreadListFilter::Mine,
            null,
            $validated['sources'] ?? [],
            $validated['starters'] ?? [],
        ));
    }
}
