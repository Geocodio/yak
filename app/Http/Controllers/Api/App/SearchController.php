<?php

namespace App\Http\Controllers\Api\App;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\App\SearchRequest;
use App\Http\Resources\AppSearchData;
use App\Services\PaletteSearch;
use Illuminate\Http\JsonResponse;

class SearchController extends Controller
{
    public function __invoke(SearchRequest $request, PaletteSearch $search): JsonResponse
    {
        return response()->json(AppSearchData::from(
            $search->search($request->user() ?? abort(401), $request->searchText()),
        ));
    }
}
