<?php

namespace App\Http\Controllers;

use App\Http\Resources\PaletteSearchData;
use App\Services\PaletteSearch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaletteSearchController extends Controller
{
    public function __invoke(Request $request, PaletteSearch $search): JsonResponse
    {
        $validated = $request->validate(['query' => ['nullable', 'string', 'max:100']]);

        return response()->json(PaletteSearchData::from(
            $search->search($request->user() ?? abort(401), (string) ($validated['query'] ?? '')),
        ));
    }
}
