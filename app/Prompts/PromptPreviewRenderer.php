<?php

namespace App\Prompts;

use App\Services\PromptResolver;
use App\Support\Markdown;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\View;
use Throwable;

/**
 * Renders prompt content against a sample fixture for the editor's live
 * preview pane. Shared by `PromptPreviewController` (the debounced live
 * preview request) and `PromptController` (the initial page load, so it
 * carries a preview without a client round trip) -- neither controller
 * instantiates the other.
 */
class PromptPreviewRenderer
{
    /**
     * @return array{ok: bool, body?: string, bodyHtml?: string, error?: string}
     */
    public function render(string $slug, string $content, int $fixtureIndex): array
    {
        $fixtures = PromptFixtures::for($slug);
        $data = $fixtures[$fixtureIndex]['data'] ?? [];

        $errors = PromptResolver::checkDisallowedDirectives($content);

        if ($errors !== []) {
            return ['ok' => false, 'error' => $errors[0]];
        }

        try {
            $body = trim(Blade::render($content, $data));

            return ['ok' => true, 'body' => $body, 'bodyHtml' => Markdown::toHtml($body)];
        } catch (Throwable $e) {
            $factory = View::getFacadeRoot();
            if (is_object($factory) && method_exists($factory, 'flushState')) {
                $factory->flushState();
            }

            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }
}
