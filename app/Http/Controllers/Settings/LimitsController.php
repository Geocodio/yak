<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\UpdateLimitsRequest;
use App\Http\Resources\LimitsData;
use App\Models\ClaudeAccount;
use App\Services\Settings\CapacitySuggestion;
use App\Services\Settings\EditableSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class LimitsController extends Controller
{
    public function index(Request $request, EditableSettings $settings): Response
    {
        $this->authorizeAdmin($request);

        return Inertia::render('Settings/Limits', [
            'groups' => LimitsData::groups($settings),
            'suggestion' => Inertia::defer(fn (): array => app(CapacitySuggestion::class)->compute(), 'suggestion'),
        ]);
    }

    public function update(UpdateLimitsRequest $request, EditableSettings $settings): RedirectResponse
    {
        $submitted = $request->array('values');

        foreach ($settings->definitions() as $definition) {
            if (array_key_exists($definition['id'], $submitted)) {
                $settings->store($definition, $this->castValue($definition['type'], $submitted[$definition['id']]));
            }
        }

        $settings->refresh();

        return back()->with('success', 'Limits saved.');
    }

    public function reset(Request $request, EditableSettings $settings): RedirectResponse
    {
        $this->authorizeAdmin($request);
        $settings->resetAll();

        return back()->with('success', 'Limits reset to the defaults.');
    }

    private function authorizeAdmin(Request $request): void
    {
        abort_unless($request->user()?->can('create', [ClaudeAccount::class, true]) === true, 403);
    }

    private function castValue(string $type, mixed $value): int|float|bool
    {
        return match ($type) {
            'bool' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            'decimal' => (float) $value,
            default => (int) $value,
        };
    }
}
