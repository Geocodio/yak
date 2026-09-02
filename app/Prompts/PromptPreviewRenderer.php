<?php

namespace App\Prompts;

use App\Services\PromptResolver;
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
