<?php

namespace App\Services;

use App\Models\Prompt;
use App\Prompts\PromptDefinitions;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\View;
use Throwable;

/**
 * Renders a prompt by slug, preferring DB-stored customized content and
 * falling back to the canonical Blade template on disk.
 *
 * Every prompt render — whether from YakPromptBuilder, a Laravel AI SDK
 * agent, or the editor's preview — flows through here so there is a single
 * place to reason about override, validation, and safety fallbacks.
 */
class PromptResolver
{
    /**
     * List of Blade directives that are disallowed inside user-saved prompt
     * content. These either pull in external state or execute arbitrary PHP
     * and make the rendered surface unpredictable.
     *
     * @var array<int, string>
     */
    public const DISALLOWED_DIRECTIVES = [
        'include',
        'includeIf',
        'includeWhen',
        'includeUnless',
        'includeFirst',
        'extends',
        'component',
        'slot',
        'php',
    ];

    /**
     * Render a prompt by slug, with DB override if customized.
     *
     * Falls back to the canonical Blade file if DB rendering throws, so a
     * bad save never breaks the task pipeline.
     *
     * @param  array<string, mixed>  $data
     */
    public function render(string $slug, array $data = []): string
    {
        $definition = PromptDefinitions::for($slug);
        $view = $definition['view'];

        /** @var Prompt|null $prompt */
        $prompt = Prompt::where('slug', $slug)->first();

        if ($prompt && $prompt->is_customized && trim((string) $prompt->content) !== '') {
            try {
                return trim(Blade::render((string) $prompt->content, $data));
            } catch (Throwable $e) {
                Log::warning('PromptResolver: customized prompt failed to render, falling back to file', [
                    'slug' => $slug,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return trim(View::make($view, $data)->render());
    }

    /**
     * Render the canonical Blade file for a slug, ignoring any DB override.
     *
     * Used by the editor when showing "Reset to Default" content and by the
     * first-save baseline snapshot.
     *
     * @param  array<string, mixed>  $data
     */
    public function renderDefault(string $slug, array $data = []): string
    {
        $view = PromptDefinitions::view($slug);

        return trim(View::make($view, $data)->render());
    }

    /**
     * Raw contents of the canonical Blade file for a slug. Returned as the
     * first "default" version when a user makes their first edit.
     */
    public function fileContent(string $slug): string
    {
        $view = PromptDefinitions::view($slug);

        return View::getFinder()->find($view) ? (string) file_get_contents(View::getFinder()->find($view)) : '';
    }

    /**
     * Validate prompt content for save. Returns a (possibly empty) list of
     * human-readable error strings. Callers reject the save if non-empty.
     *
     * @param  array<string, mixed>  $fixture
     * @return array<int, string>
     */
    public function validate(string $content, array $fixture = []): array
    {
        $errors = [];

        foreach (self::DISALLOWED_DIRECTIVES as $directive) {
            if (preg_match('/@' . preg_quote($directive, '/') . '\b/', $content) === 1) {
                $errors[] = "Directive @{$directive} is not allowed in prompts.";
            }
        }

        if ($errors !== []) {
            return $errors;
        }

        try {
            Blade::compileString($content);
        } catch (Throwable $e) {
            $errors[] = 'Blade compile error: ' . $e->getMessage();

            return $errors;
        }

        try {
            Blade::render($content, $fixture);
        } catch (Throwable $e) {
            $errors[] = 'Template failed to render against the sample fixture: ' . $e->getMessage();
        }

        return $errors;
    }
}
