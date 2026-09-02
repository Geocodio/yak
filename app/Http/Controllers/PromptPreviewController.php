<?php

namespace App\Http\Controllers;

use App\Prompts\PromptDefinitions;
use App\Prompts\PromptFixtures;
use App\Services\PromptResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\View;
use Throwable;

class PromptPreviewController extends Controller
{
    public function __invoke(Request $request, string $slug): JsonResponse
    {
        abort_unless(PromptDefinitions::has($slug), 404);

        $content = $request->string('content')->toString();
        $fixtureIndex = $request->integer('fixture', 0);

        return response()->json($this->render($slug, $content, $fixtureIndex));
    }

    /**
     * Renders the given content against a prompt's sample fixture. Shared
     * with `PromptController` so the initial page load carries a preview
     * without a client round trip.
     *
     * @return array{ok: bool, body?: string, error?: string}
     */
    public function render(string $slug, string $content, int $fixtureIndex): array
    {
        $fixtures = PromptFixtures::for($slug);
        $data = $fixtures[$fixtureIndex]['data'] ?? [];

        foreach (PromptResolver::DISALLOWED_DIRECTIVES as $directive) {
            if (preg_match('/@' . preg_quote($directive, '/') . '\b/', $content) === 1) {
                return ['ok' => false, 'error' => "Directive @{$directive} is not allowed in prompts."];
            }
        }

        try {
            return ['ok' => true, 'body' => trim(Blade::render($content, $data))];
        } catch (Throwable $e) {
            $factory = View::getFacadeRoot();
            if (is_object($factory) && method_exists($factory, 'flushState')) {
                $factory->flushState();
            }

            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }
}
