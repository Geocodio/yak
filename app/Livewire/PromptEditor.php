<?php

namespace App\Livewire;

use App\Models\Prompt;
use App\Models\PromptVersion;
use App\Prompts\PromptDefinitions;
use App\Prompts\PromptFixtures;
use App\Services\PromptResolver;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View as BladeView;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Throwable;

/**
 * @property array<string, array{label: string, category: string, type: string}> $sidebarPrompts
 * @property array<int, string> $availableVariables
 * @property array<int, array{label: string, data: array<string, mixed>}> $fixtures
 * @property array<int, string> $unusedVariables
 * @property array<int, string> $unknownVariables
 * @property Collection<int, PromptVersion> $versions
 * @property bool $isCustomized
 */
#[Title('Prompts')]
class PromptEditor extends Component
{
    public ?string $selectedSlug = null;

    public string $content = '';

    public int $fixtureIndex = 0;

    public ?int $selectedVersionId = null;

    public string $saveMessage = '';

    public bool $saveError = false;

    public string $previewBody = '';

    public bool $previewOk = true;

    public bool $showHistory = false;

    public bool $showDiff = false;

    public bool $showResetConfirm = false;

    public function mount(): void
    {
        $first = array_key_first(PromptDefinitions::all());
        if ($first !== null) {
            $this->selectPrompt($first);
        }
    }

    public function selectPrompt(string $slug): void
    {
        if (! PromptDefinitions::has($slug)) {
            return;
        }

        $this->selectedSlug = $slug;
        $this->fixtureIndex = 0;
        $this->selectedVersionId = null;
        $this->saveMessage = '';
        $this->saveError = false;
        $this->showHistory = false;
        $this->showDiff = false;
        $this->showResetConfirm = false;

        $prompt = $this->promptRecord();

        if ($prompt && $prompt->is_customized && trim((string) $prompt->content) !== '') {
            $this->content = (string) $prompt->content;
        } else {
            $this->content = $this->defaultContent($slug);
        }

        $this->refreshPreview();
    }

    public function updatedContent(): void
    {
        $this->refreshPreview();
    }

    public function updatedFixtureIndex(): void
    {
        $this->refreshPreview();
    }

    private function refreshPreview(): void
    {
        if ($this->selectedSlug === null) {
            $this->previewOk = true;
            $this->previewBody = '';

            return;
        }

        $fixtures = PromptFixtures::for($this->selectedSlug);
        $data = $fixtures[$this->fixtureIndex]['data'] ?? [];

        foreach (PromptResolver::DISALLOWED_DIRECTIVES as $directive) {
            if (preg_match('/@' . preg_quote($directive, '/') . '\b/', $this->content) === 1) {
                $this->previewOk = false;
                $this->previewBody = "Directive @{$directive} is not allowed in prompts.";

                return;
            }
        }

        try {
            $this->previewBody = trim(Blade::render($this->content, $data));
            $this->previewOk = true;
        } catch (Throwable $e) {
            $factory = \Illuminate\Support\Facades\View::getFacadeRoot();
            if (is_object($factory) && method_exists($factory, 'flushState')) {
                $factory->flushState();
            }

            $this->previewOk = false;
            $this->previewBody = $e->getMessage();
        }
    }

    public function save(PromptResolver $resolver): void
    {
        if ($this->selectedSlug === null) {
            return;
        }

        $slug = $this->selectedSlug;
        $fixture = PromptFixtures::firstData($slug);

        $errors = $resolver->validate($this->content, $fixture);

        if ($errors !== []) {
            $this->saveError = true;
            $this->saveMessage = $errors[0];

            return;
        }

        try {
            DB::transaction(function () use ($slug): void {
                $prompt = Prompt::where('slug', $slug)->lockForUpdate()->firstOrFail();

                $hasVersions = PromptVersion::where('prompt_id', $prompt->id)->exists();

                $nextVersion = (int) PromptVersion::where('prompt_id', $prompt->id)->max('version') + 1;

                if (! $hasVersions) {
                    PromptVersion::create([
                        'prompt_id' => $prompt->id,
                        'content' => $this->defaultContent($slug),
                        'version' => $nextVersion,
                        'created_at' => now(),
                    ]);
                    $nextVersion++;
                }

                PromptVersion::create([
                    'prompt_id' => $prompt->id,
                    'content' => $this->content,
                    'version' => $nextVersion,
                    'created_at' => now(),
                ]);

                $prompt->content = $this->content;
                $prompt->is_customized = true;
                $prompt->save();
            });

            $this->saveError = false;
            $this->saveMessage = 'Saved.';
        } catch (Throwable $e) {
            Log::warning('PromptEditor: save failed', [
                'slug' => $slug,
                'error' => $e->getMessage(),
            ]);
            $this->saveError = true;
            $this->saveMessage = 'Could not save: ' . $e->getMessage();
        }
    }

    public function confirmReset(): void
    {
        $this->showResetConfirm = true;
    }

    public function cancelReset(): void
    {
        $this->showResetConfirm = false;
    }

    public function resetToDefault(): void
    {
        if ($this->selectedSlug === null) {
            return;
        }

        $prompt = $this->promptRecord();
        if ($prompt) {
            $prompt->content = null;
            $prompt->is_customized = false;
            $prompt->save();
        }

        $this->content = $this->defaultContent($this->selectedSlug);
        $this->showResetConfirm = false;
        $this->saveError = false;
        $this->saveMessage = 'Reset to default.';
    }

    public function openHistory(): void
    {
        $this->showHistory = true;
    }

    public function closeHistory(): void
    {
        $this->showHistory = false;
    }

    public function loadVersion(int $versionId): void
    {
        /** @var PromptVersion|null $version */
        $version = PromptVersion::find($versionId);

        if ($version === null) {
            return;
        }

        $prompt = $this->promptRecord();
        if (! $prompt || $version->prompt_id !== $prompt->id) {
            return;
        }

        $this->content = $version->content;
        $this->selectedVersionId = $versionId;
        $this->showHistory = false;
        $this->saveMessage = "Loaded version {$version->version} (unsaved).";
        $this->saveError = false;
    }

    public function toggleDiff(): void
    {
        $this->showDiff = ! $this->showDiff;
    }

    public function setFixture(int $index): void
    {
        $fixtures = $this->fixtures;
        if (isset($fixtures[$index])) {
            $this->fixtureIndex = $index;
        }
    }

    /**
     * @return array<string, array{label: string, category: string, type: string}>
     */
    #[Computed]
    public function sidebarPrompts(): array
    {
        $out = [];
        foreach (PromptDefinitions::all() as $slug => $def) {
            $out[$slug] = [
                'label' => $def['label'],
                'category' => $def['category'],
                'type' => $def['type'],
            ];
        }

        return $out;
    }

    /**
     * @return array<int, string>
     */
    #[Computed]
    public function availableVariables(): array
    {
        if ($this->selectedSlug === null) {
            return [];
        }

        return PromptDefinitions::variables($this->selectedSlug);
    }

    /**
     * @return array<int, array{label: string, data: array<string, mixed>}>
     */
    #[Computed]
    public function fixtures(): array
    {
        if ($this->selectedSlug === null) {
            return [];
        }

        return PromptFixtures::for($this->selectedSlug);
    }

    /**
     * @return array<int, string>
     */
    #[Computed]
    public function unusedVariables(): array
    {
        $available = $this->availableVariables;

        return array_values(array_filter($available, function (string $var): bool {
            return preg_match('/\$' . preg_quote($var, '/') . '\b/', $this->content) !== 1;
        }));
    }

    /**
     * @return array<int, string>
     */
    #[Computed]
    public function unknownVariables(): array
    {
        if (preg_match_all('/\{\{\s*\$([A-Za-z_][A-Za-z0-9_]*)\b|\{!!\s*\$([A-Za-z_][A-Za-z0-9_]*)\b/', $this->content, $matches) === false) {
            return [];
        }

        $found = array_values(array_filter(array_unique(array_merge($matches[1], $matches[2]))));
        $available = $this->availableVariables;

        return array_values(array_diff($found, $available));
    }

    /**
     * @return Collection<int, PromptVersion>
     */
    #[Computed]
    public function versions()
    {
        $prompt = $this->promptRecord();

        if ($prompt === null) {
            return collect();
        }

        return $prompt->versions()->get();
    }

    #[Computed]
    public function isCustomized(): bool
    {
        $prompt = $this->promptRecord();

        return $prompt !== null && $prompt->is_customized;
    }

    public function render(): View|BladeView
    {
        return view('livewire.prompt-editor');
    }

    private function promptRecord(): ?Prompt
    {
        if ($this->selectedSlug === null) {
            return null;
        }

        /** @var Prompt|null $prompt */
        $prompt = Prompt::where('slug', $this->selectedSlug)->first();

        return $prompt;
    }

    private function defaultContent(string $slug): string
    {
        return app(PromptResolver::class)->fileContent($slug);
    }
}
