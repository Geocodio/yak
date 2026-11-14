<?php

namespace Tests\Support;

use PHPUnit\Framework\Assert;

/**
 * Compares a response body with its checked-in contract fixture.
 */
class ContractFixture
{
    public const VOLATILE_MARKER = '<volatile>';

    /**
     * @param  'cli'|'app'  $api
     * @param  array<mixed>  $payload
     * @param  list<string>  $volatile  dot paths, `*` matches any key, whose values are replaced by the marker
     */
    public static function assertMatches(string $api, string $name, array $payload, array $volatile = []): void
    {
        $path = base_path("tests/Fixtures/{$api}-api/v1/{$name}.json");
        $actual = self::render(self::sortKeys(self::maskVolatile($payload, $volatile)));

        if (getenv('UPDATE_CONTRACT_FIXTURES') === '1') {
            if (! is_dir(dirname($path))) {
                mkdir(dirname($path), 0755, true);
            }
            file_put_contents($path, $actual);
            Assert::assertTrue(true);

            return;
        }

        Assert::assertFileExists($path, "Contract fixture {$api}-api/v1/{$name}.json is missing. Run with UPDATE_CONTRACT_FIXTURES=1 to write it.");
        Assert::assertSame(file_get_contents($path), $actual, "Response differs from contract fixture {$api}-api/v1/{$name}.json. A deliberate change needs UPDATE_CONTRACT_FIXTURES=1 and a changelog entry.");
    }

    /**
     * @param  array<mixed>  $payload
     * @param  list<string>  $volatile
     * @return array<mixed>
     */
    private static function maskVolatile(array $payload, array $volatile): array
    {
        foreach ($volatile as $pattern) {
            self::mask($payload, explode('.', $pattern));
        }

        return $payload;
    }

    /**
     * @param  array<mixed>  $node
     * @param  list<string>  $segments
     */
    private static function mask(array &$node, array $segments): void
    {
        $segment = array_shift($segments);
        $keys = $segment === '*' ? array_keys($node) : (array_key_exists($segment, $node) ? [$segment] : []);

        foreach ($keys as $key) {
            if ($segments === []) {
                $node[$key] = self::VOLATILE_MARKER;
            } elseif (is_array($node[$key])) {
                self::mask($node[$key], $segments);
            }
        }
    }

    private static function sortKeys(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $value = array_map(self::sortKeys(...), $value);

        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }

    private static function render(mixed $value): string
    {
        return json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
    }
}
