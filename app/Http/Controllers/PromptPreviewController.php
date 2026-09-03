<?php

namespace App\Http\Controllers;

use App\Prompts\PromptDefinitions;
use App\Prompts\PromptPreviewRenderer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PromptPreviewController extends Controller
{
    public function __invoke(Request $request, string $slug, PromptPreviewRenderer $renderer): JsonResponse
    {
        abort_unless(PromptDefinitions::has($slug), 404);

        $content = $request->string('content')->toString();
        $fixtureIndex = $request->integer('fixture', 0);

        return response()->json($renderer->render($slug, $content, $fixtureIndex));
    }
}
