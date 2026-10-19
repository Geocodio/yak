<?php

namespace App\Support;

/**
 * GitHub CODEOWNERS rules. The last matching line wins, and a line with no
 * owners leaves a path unowned. Each pattern compiles to its own regex with
 * GitHub's semantics: a leading `/` or an inner `/` anchors to the
 * repository root, otherwise the pattern matches at any depth. `*` and `?`
 * stay inside one path segment, `**` spans directories, and a final segment
 * with a wildcard does not recurse. A literal name or a trailing `/` covers a
 * directory and everything under it.
 * Owners are kept as written (`@login`, `@org/team`, email); resolving them
 * to Yak users happens elsewhere.
 */
final readonly class CodeOwners
{
    public const LOCATIONS = ['.github/CODEOWNERS', 'CODEOWNERS', 'docs/CODEOWNERS'];

    public const MAX_RULES = 500;

    public const MAX_PATTERN_LENGTH = 500;

    /** @param list<array{regex: string, owners: list<string>}> $rules */
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

            $tokens = preg_split('/(?<!\\\\)\s+/', $line) ?: [];
            $pattern = (string) array_shift($tokens);
            $owners = array_values(array_filter(
                $tokens,
                fn (string $token): bool => preg_match('/^(@[A-Za-z0-9_.-]+(\/[A-Za-z0-9_.-]+)?|[^@\s]+@[^@\s]+\.[^@\s]+)$/', $token) === 1,
            ));
            $regex = self::compile($pattern);

            if ($regex === null) {
                continue;
            }

            $rules[] = ['regex' => $regex, 'owners' => $owners];

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
            if (preg_match($this->rules[$index]['regex'], $path) === 1) {
                return $this->rules[$index]['owners'];
            }
        }

        return [];
    }

    public function ruleCount(): int
    {
        return count($this->rules);
    }

    /** Returns null for a pattern that is too long or does not compile. */
    private static function compile(string $pattern): ?string
    {
        if (strlen($pattern) > self::MAX_PATTERN_LENGTH) {
            return null;
        }

        $pattern = str_replace(['\#', '\ '], ['#', ' '], $pattern);
        $isDirectoryOnly = str_ends_with($pattern, '/');
        $body = trim($pattern, '/');
        $isAnchored = str_starts_with($pattern, '/') || str_contains($body, '/');
        $hasTrailingRecursion = str_ends_with($body, '/**');
        $lastSegment = substr($body, (int) strrpos($body, '/'));
        $hasWildcard = strpbrk($lastSegment, '*?') !== false;

        if ($hasTrailingRecursion) {
            $body = substr($body, 0, -3);
        }

        $regex = '';
        $length = strlen($body);

        for ($offset = 0; $offset < $length; $offset++) {
            $character = $body[$offset];

            if ($character === '*' && substr($body, $offset, 3) === '**/' && ($offset === 0 || $body[$offset - 1] === '/')) {
                $regex .= '(?:.*/)?';
                $offset += 2;
            } elseif ($character === '*') {
                $regex .= '[^/]*';
                $offset += ($body[$offset + 1] ?? '') === '*' ? 1 : 0;
            } elseif ($character === '?') {
                $regex .= '[^/]';
            } else {
                $regex .= preg_quote($character, '#');
            }
        }

        $suffix = match (true) {
            $hasTrailingRecursion => '/.*',
            $isDirectoryOnly => '/.+',
            $hasWildcard => '',
            default => '(?:/.*)?',
        };
        $compiled = '#^' . ($isAnchored ? '' : '(?:.*/)?') . $regex . $suffix . '$#s';

        return @preg_match($compiled, '') === false ? null : $compiled;
    }
}
