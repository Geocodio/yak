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
     * Prefix that a `@include(...)` argument must resolve to in order to be
     * permitted. Shipped default templates pull in shared boilerplate (e.g.
     * the clarification contract) this way; only that namespace is safe
     * because it points at views this app ships and controls, not
     * user-reachable content.
     */
    private const ALLOWED_INCLUDE_PREFIX = 'prompts.partials.';

    /**
     * Check prompt content for disallowed Blade directives.
     *
     * `@include` is allowed only when its single string-literal argument
     * names a view under {@see self::ALLOWED_INCLUDE_PREFIX}. Every other
     * directive in {@see self::DISALLOWED_DIRECTIVES} is rejected outright,
     * as is `@include` with a dynamic or out-of-namespace argument.
     *
     * @return array<int, string>
     */
    public static function checkDisallowedDirectives(string $content): array
    {
        $errors = [];

        foreach (self::DISALLOWED_DIRECTIVES as $directive) {
            if (preg_match('/@' . preg_quote($directive, '/') . '\b/', $content) !== 1) {
                continue;
            }

            if ($directive === 'include') {
                $errors = array_merge($errors, self::checkIncludeDirectives($content));

                continue;
            }

            $errors[] = "Directive @{$directive} is not allowed in prompts.";
        }

        return $errors;
    }

    /**
     * Validate every `@include(...)` occurrence, allowing only literal
     * references to views under {@see self::ALLOWED_INCLUDE_PREFIX}.
     *
     * @return array<int, string>
     */
    private static function checkIncludeDirectives(string $content): array
    {
        $errors = [];

        preg_match_all('/@include\s*\(([^)]*)\)/', $content, $matches);

        foreach ($matches[1] as $argument) {
            $argument = trim($argument);

            if (preg_match('/^([\'"])(.*)\1$/', $argument, $literal) !== 1) {
                $errors[] = "Directive @include is only allowed with a literal view name under '" . self::ALLOWED_INCLUDE_PREFIX . "'.";

                continue;
            }

            $view = $literal[2];

            if (! str_starts_with($view, self::ALLOWED_INCLUDE_PREFIX)) {
                $errors[] = "Directive @include is only allowed with a literal view name under '" . self::ALLOWED_INCLUDE_PREFIX . "'.";
            }
        }

        return $errors;
    }

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
        $errors = self::checkDisallowedDirectives($content);

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
