<?php

namespace App\Http\Controllers;

use App\Exceptions\ClaudeCliException;
use App\Http\Requests\Skills\AddMarketplaceRequest;
use App\Services\SkillManager;
use Illuminate\Http\RedirectResponse;

class MarketplaceController extends Controller
{
    public function __construct(private readonly SkillManager $skills) {}

    public function store(AddMarketplaceRequest $request): RedirectResponse
    {
        $source = $request->validated()['source'];

        return $this->runSafely(function () use ($source) {
            $this->skills->addMarketplace($source);

            return 'Marketplace added.';
        });
    }

    public function destroy(string $name): RedirectResponse
    {
        return $this->runSafely(function () use ($name) {
            $this->skills->removeMarketplace($name);

            return "Removed marketplace {$name}.";
        });
    }

    public function refresh(): RedirectResponse
    {
        return $this->runSafely(function () {
            $this->skills->refreshMarketplaces();

            return 'Marketplaces refreshed.';
        });
    }

    private function runSafely(\Closure $action): RedirectResponse
    {
        try {
            $message = $action();
        } catch (ClaudeCliException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', $message);
    }
}
