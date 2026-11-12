<?php

namespace App\Services\ClaudeAccounts;

use App\Models\ClaudeAccount;
use App\Services\Threads\AuthProxyPlaceholders;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\In;
use Throwable;

/**
 * The Claude models Yak offers. The list comes from the Anthropic Models API,
 * fetched through the auth proxy with a placeholder like an account check
 * uses, and the last good list is kept in the cache with no expiry. Until a
 * fetch has succeeded the seed list in config stands in.
 *
 * A model value is either a family alias (`opus`, `fable`, `sonnet`, `haiku`),
 * which means the newest model of that family, or an exact model id, which
 * pins that version.
 *
 * @phpstan-type CatalogEntry array{id: string, family: string, label: string, description: string, isLatestInFamily: bool, createdAt: ?string}
 */
class ClaudeModelCatalog
{
    public const string CACHE_KEY = 'claude-model-catalog';

    /** Families in the order the pickers list them. */
    public const array FAMILIES = ['opus', 'fable', 'sonnet', 'haiku'];

    private const array DESCRIPTIONS = [
        'opus' => 'For complex work and everyday tasks',
        'fable' => 'For your toughest challenges',
        'sonnet' => 'Most efficient for simpler tasks',
        'haiku' => 'Fastest for quick answers',
    ];

    public function __construct(private readonly AuthProxyPlaceholders $placeholders) {}

    /**
     * Every known model, newest first, with the newest of each family flagged.
     *
     * @return list<CatalogEntry>
     */
    public function entries(): array
    {
        $stored = Cache::get(self::CACHE_KEY);
        $models = is_array($stored) && $stored !== [] ? $stored : $this->seed();

        usort($models, fn (array $left, array $right): int => strcmp((string) $right['createdAt'], (string) $left['createdAt']));

        $seen = [];

        return array_map(function (array $model) use (&$seen): array {
            $isLatest = ! isset($seen[$model['family']]);
            $seen[$model['family']] = true;

            return ['id' => $model['id'], 'family' => $model['family'], 'label' => $model['label'], 'description' => self::DESCRIPTIONS[$model['family']], 'isLatestInFamily' => $isLatest, 'createdAt' => $model['createdAt']];
        }, $models);
    }

    /**
     * The picker shape: one row per family with its newest model, plus the older exact versions.
     *
     * @return list<array{family: string, latest: array{value: string, label: string, description: string}, older: list<array{value: string, label: string}>}>
     */
    public function families(): array
    {
        $entries = $this->entries();
        $families = [];

        foreach (self::FAMILIES as $family) {
            $inFamily = array_values(array_filter($entries, fn (array $entry): bool => $entry['family'] === $family));

            if ($inFamily === []) {
                continue;
            }

            $families[] = [
                'family' => $family,
                'latest' => ['value' => $family, 'label' => $inFamily[0]['label'], 'description' => self::DESCRIPTIONS[$family]],
                'older' => array_map(fn (array $entry): array => ['value' => $entry['id'], 'label' => $entry['label']], array_slice($inFamily, 1)),
            ];
        }

        return $families;
    }

    /**
     * Aliases plus every exact id.
     *
     * @return list<string>
     */
    public function acceptedValues(): array
    {
        return [...self::FAMILIES, ...array_column($this->entries(), 'id')];
    }

    public static function rule(): In
    {
        return Rule::in(app(self::class)->acceptedValues());
    }

    /**
     * The exact id behind a family alias; any other value, or an alias the catalog has no model for, comes back unchanged.
     */
    public function resolve(string $model): string
    {
        foreach ($this->entries() as $entry) {
            if ($entry['family'] === $model && $entry['isLatestInFamily']) {
                return $entry['id'];
            }
        }

        return $model;
    }

    /**
     * Fetch the list through the auth proxy and keep it. A failed fetch keeps the last good list.
     */
    public function refresh(): bool
    {
        $account = $this->account();

        if ($account === null) {
            return false;
        }

        $subject = 'validation-catalog-' . Str::lower(Str::random(12));

        try {
            $placeholder = $this->placeholders->issue($subject, $account, AuthProxyPlaceholders::VALIDATION_BINDING);
            $models = $this->fetch($placeholder);
        } catch (Throwable) {
            return false;
        } finally {
            try {
                $this->placeholders->revoke($subject);
            } catch (Throwable) {
                // The placeholder expires on its own within minutes.
            }
        }

        if ($models === []) {
            return false;
        }

        Cache::forever(self::CACHE_KEY, $models);

        return true;
    }

    /**
     * The proxy swaps the bearer placeholder for the account secret. The OAuth beta flag is
     * needed for setup tokens and the proxy drops it again for API keys.
     *
     * @return list<array{id: string, family: string, label: string, createdAt: ?string}>
     */
    private function fetch(string $placeholder): array
    {
        $models = [];
        $afterId = null;

        do {
            $response = Http::connectTimeout(5)->timeout(20)
                ->withHeaders([
                    'Authorization' => 'Bearer ' . $placeholder,
                    'anthropic-version' => '2023-06-01',
                    'anthropic-beta' => 'oauth-2025-04-20',
                ])
                ->get(rtrim((string) config('yak.threads.auth_proxy_url'), '/') . '/v1/models', array_filter(['limit' => 1000, 'after_id' => $afterId]))
                ->throw();

            foreach ((array) $response->json('data', []) as $item) {
                $model = is_array($item) ? $this->parse($item) : null;

                if ($model !== null) {
                    $models[$model['id']] = $model;
                }
            }

            $afterId = $response->json('has_more') === true ? $response->json('last_id') : null;
        } while (is_string($afterId) && $afterId !== '');

        return array_values($models);
    }

    /**
     * @param  array<mixed>  $item
     * @return array{id: string, family: string, label: string, createdAt: ?string}|null
     */
    private function parse(array $item): ?array
    {
        $id = $item['id'] ?? null;

        if (! is_string($id) || preg_match('/^claude-.*?(opus|fable|sonnet|haiku)/', $id, $match) !== 1) {
            return null;
        }

        $name = is_string($item['display_name'] ?? null) && $item['display_name'] !== '' ? $item['display_name'] : $id;
        $createdAt = is_string($item['created_at'] ?? null) ? $item['created_at'] : null;

        return ['id' => $id, 'family' => $match[1], 'label' => Str::chopStart($name, 'Claude '), 'createdAt' => $createdAt];
    }

    /**
     * @return list<array{id: string, family: string, label: string, createdAt: ?string}>
     */
    private function seed(): array
    {
        return array_values(array_map(fn (array $model): array => ['id' => $model['id'], 'family' => $model['family'], 'label' => $model['label'], 'createdAt' => null], (array) config('claude-accounts.seed_models')));
    }

    private function account(): ?ClaudeAccount
    {
        $global = ClaudeAccount::globalSystemAccount();

        if ($global !== null && $global->isUsable()) {
            return $global;
        }

        return ClaudeAccount::query()->latest('id')->get()->first(fn (ClaudeAccount $account): bool => $account->isUsable());
    }
}
