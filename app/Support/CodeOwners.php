<?php

namespace App\Support;

/**
 * GitHub CODEOWNERS rules. The last matching line wins, and a line with no
 * owners leaves a path unowned. Patterns follow gitignore anchoring: a
 * leading `/` or an inner `/` anchors to the repository root; otherwise the
 * pattern matches at any depth. A trailing `/` matches directories only.
 * Owners are kept as written (`@login`, `@org/team`, email); resolving them
 * to Yak users happens elsewhere.
 */
final readonly class CodeOwners
{
    public const LOCATIONS = ['.github/CODEOWNERS', 'CODEOWNERS', 'docs/CODEOWNERS'];

    public const MAX_RULES = 500;

    /** @param list<array{matchers: list<\Closure(string): ?bool>, owners: list<string>}> $rules */
    private function __construct(private array $rules) {}

    public static function empty(): self
    {
        return new self([]);
    }

    public static function parse(string $content): self
    {
        $rules = [];

        foreach (preg_split('/\R/', $content) ?: [] as $line) {
            $line = trim((string) preg_replace('/(?<!\\\\)#.*$/', '', $line));

            if ($line === '') {
                continue;
            }

            $tokens = preg_split('/\s+/', $line) ?: [];
            $pattern = (string) array_shift($tokens);
            $owners = array_values(array_filter(
                $tokens,
                fn (string $token): bool => preg_match('/^(@[A-Za-z0-9_.-]+(\/[A-Za-z0-9_.-]+)?|[^@\s]+@[^@\s]+\.[^@\s]+)$/', $token) === 1,
            ));
            $rules[] = ['matchers' => array_map(PathMatcher::compile(...), self::globs($pattern)), 'owners' => $owners];

            if (count($rules) === self::MAX_RULES) {
                break;
            }
        }

        return new self($rules);
    }

    /** @return list<string> */
    public function ownersFor(string $path): array
    {
        for ($index = count($this->rules) - 1; $index >= 0; $index--) {
            foreach ($this->rules[$index]['matchers'] as $matcher) {
                if ($matcher($path) === true) {
                    return $this->rules[$index]['owners'];
                }
            }
        }

        return [];
    }

    public function ruleCount(): int
    {
        return count($this->rules);
    }

    /** @return list<string> */
    private static function globs(string $pattern): array
    {
        $isDirectoryOnly = str_ends_with($pattern, '/');
        $body = trim($pattern, '/');
        $isAnchored = str_starts_with($pattern, '/') || str_contains($body, '/');
        $bases = $isAnchored ? [$body] : [$body, "**/{$body}"];
        $globs = [];

        foreach ($bases as $base) {
            if (! $isDirectoryOnly) {
                $globs[] = $base;
            }
            $globs[] = "{$base}/**";
        }

        return $globs;
    }
}
