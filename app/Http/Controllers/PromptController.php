<?php

namespace App\Http\Controllers;

use App\Http\Requests\Prompts\SavePromptRequest;
use App\Models\Prompt;
use App\Models\PromptVersion;
use App\Prompts\PromptDefinitions;
use App\Prompts\PromptFixtures;
use App\Prompts\PromptPreviewRenderer;
use App\Services\PromptResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class PromptController extends Controller
{
    public function index(): RedirectResponse
    {
        $first = array_key_first(PromptDefinitions::all());

        abort_if($first === null, 404);

        return redirect()->route('prompts.show', $first);
    }

    public function show(string $slug, PromptResolver $resolver, PromptPreviewRenderer $renderer): Response
    {
        abort_unless(PromptDefinitions::has($slug), 404);

        return Inertia::render('Prompts/Index', [
            'prompts' => fn () => $this->sidebarGroups(),
            'prompt' => fn () => $this->promptData($slug, $resolver),
            'fixtures' => fn () => $this->fixtureOptions($slug),
            'fixtureIndex' => 0,
            'preview' => fn () => $renderer->render($slug, $this->currentContent($slug, $resolver), 0),
            'versions' => fn () => $this->versionsData($slug),
        ]);
    }

    public function update(SavePromptRequest $request, string $slug, PromptResolver $resolver): RedirectResponse
    {
        abort_unless(PromptDefinitions::has($slug), 404);

        $content = $request->string('content')->toString();
        $fixture = PromptFixtures::firstData($slug);

        $errors = $resolver->validate($content, $fixture);

        if ($errors !== []) {
            throw ValidationException::withMessages(['content' => $errors[0]]);
        }

        try {
            DB::transaction(function () use ($slug, $content, $resolver): void {
                $prompt = Prompt::where('slug', $slug)->lockForUpdate()->firstOrFail();

                $hasVersions = PromptVersion::where('prompt_id', $prompt->id)->exists();
                $nextVersion = (int) PromptVersion::where('prompt_id', $prompt->id)->max('version') + 1;

                if (! $hasVersions) {
                    PromptVersion::create([
                        'prompt_id' => $prompt->id,
                        'content' => $resolver->fileContent($slug),
                        'version' => $nextVersion,
                        'created_at' => now(),
                    ]);
                    $nextVersion++;
                }

                PromptVersion::create([
                    'prompt_id' => $prompt->id,
                    'content' => $content,
                    'version' => $nextVersion,
                    'created_at' => now(),
                ]);

                $prompt->content = $content;
                $prompt->is_customized = true;
                $prompt->save();
            });
        } catch (Throwable $e) {
            Log::warning('PromptController: save failed', [
                'slug' => $slug,
                'error' => $e->getMessage(),
            ]);

            return redirect()->route('prompts.show', $slug)->with('error', 'Could not save: ' . $e->getMessage());
        }

        return redirect()->route('prompts.show', $slug)->with('success', 'Saved.');
    }

    public function reset(string $slug): RedirectResponse
    {
        abort_unless(PromptDefinitions::has($slug), 404);

        $prompt = Prompt::where('slug', $slug)->first();

        if ($prompt) {
            $prompt->content = null;
            $prompt->is_customized = false;
            $prompt->save();
        }

        return redirect()->route('prompts.show', $slug)->with('success', 'Reset to default.');
    }

    /**
     * @return array<int, array{group: string, items: array<int, array{slug: string, label: string, type: string, customized: bool}>}>
     */
    private function sidebarGroups(): array
    {
        $customized = Prompt::query()->pluck('is_customized', 'slug');

        $groups = [
            'high_touch' => ['group' => 'High-touch', 'items' => []],
            'advanced' => ['group' => 'Advanced', 'items' => []],
        ];

        foreach (PromptDefinitions::all() as $slug => $def) {
            $groups[$def['category']]['items'][] = [
                'slug' => $slug,
                'label' => $def['label'],
                'type' => $def['type'],
                'customized' => (bool) ($customized[$slug] ?? false),
            ];
        }

        return array_values($groups);
    }

    /**
     * @return array{slug: string, label: string, type: string, content: string, defaultContent: string, customized: bool, variables: array<int, string>, unusedVariables: array<int, string>, unknownVariables: array<int, string>}
     */
    private function promptData(string $slug, PromptResolver $resolver): array
    {
        $def = PromptDefinitions::for($slug);
        $content = $this->currentContent($slug, $resolver);
        $variables = $def['variables'];

        return [
            'slug' => $slug,
            'label' => $def['label'],
            'type' => $def['type'],
            'content' => $content,
            'defaultContent' => $resolver->fileContent($slug),
            'customized' => $this->isCustomized($slug),
            'variables' => $variables,
            'unusedVariables' => $this->unusedVariables($content, $variables),
            'unknownVariables' => $this->unknownVariables($content, $variables),
        ];
    }

    private function currentContent(string $slug, PromptResolver $resolver): string
    {
        $prompt = Prompt::where('slug', $slug)->first();

        if ($prompt && $prompt->is_customized && trim((string) $prompt->content) !== '') {
            return (string) $prompt->content;
        }

        return $resolver->fileContent($slug);
    }

    private function isCustomized(string $slug): bool
    {
        $prompt = Prompt::where('slug', $slug)->first();

        return $prompt !== null && $prompt->is_customized;
    }

    /**
     * @param  array<int, string>  $variables
     * @return array<int, string>
     */
    private function unusedVariables(string $content, array $variables): array
    {
        return array_values(array_filter($variables, function (string $var) use ($content): bool {
            return preg_match('/\$' . preg_quote($var, '/') . '\b/', $content) !== 1;
        }));
    }

    /**
     * @param  array<int, string>  $variables
     * @return array<int, string>
     */
    private function unknownVariables(string $content, array $variables): array
    {
        if (preg_match_all('/\{\{\s*\$([A-Za-z_][A-Za-z0-9_]*)\b|\{!!\s*\$([A-Za-z_][A-Za-z0-9_]*)\b/', $content, $matches) === false) {
            return [];
        }

        $found = array_values(array_filter(array_unique(array_merge($matches[1], $matches[2]))));

        return array_values(array_diff($found, $variables));
    }

    /**
     * @return array<int, array{index: int, label: string}>
     */
    private function fixtureOptions(string $slug): array
    {
        return array_map(
            fn (int $index, array $fixture): array => ['index' => $index, 'label' => $fixture['label']],
            array_keys(PromptFixtures::for($slug)),
            PromptFixtures::for($slug),
        );
    }

    /**
     * @return array<int, array{id: int, number: int, createdAgo: ?string, current: bool}>
     */
    private function versionsData(string $slug): array
    {
        $prompt = Prompt::where('slug', $slug)->first();

        if ($prompt === null) {
            return [];
        }

        $versions = $prompt->versions()->get();
        $latest = $versions->first();

        return $versions->map(fn (PromptVersion $version): array => [
            'id' => $version->id,
            'number' => $version->version,
            'createdAgo' => $version->created_at?->diffForHumans(),
            'current' => $latest !== null && $version->id === $latest->id,
        ])->values()->all();
    }
}
