<?php

namespace App\Services;

class ConcernsDraft
{
    /**
     * Validates the agent's concerns JSON with the same rules as a version 2
     * `.yak/risk-profile.yml`, and rejects any key beyond the three concern fields.
     *
     * @return list<array{name: string, approval: string, description: string}>
     *
     * @throws \RuntimeException
     */
    public static function parse(string $output): array
    {
        $json = trim($output);
        if (preg_match('/```json\s*(.*?)\s*```/s', $json, $match) === 1) {
            $json = $match[1];
        }

        try {
            $data = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException('Concerns draft is not valid JSON: ' . $e->getMessage());
        }

        if (! is_array($data) || array_keys($data) !== ['concerns'] || ! is_array($data['concerns']) || ! array_is_list($data['concerns'])) {
            throw new \RuntimeException('Concerns draft must be an object with only a "concerns" list.');
        }

        foreach ($data['concerns'] as $concern) {
            if (! is_array($concern) || array_diff(array_keys($concern), ['name', 'approval', 'description']) !== []) {
                throw new \RuntimeException('Each concern may only have name, approval and description.');
            }
        }

        $concerns = array_map(fn (array $concern): array => [
            'name' => (string) ($concern['name'] ?? ''),
            'approval' => (string) ($concern['approval'] ?? ''),
            'description' => (string) ($concern['description'] ?? ''),
        ], $data['concerns']);

        // Rendering validates the file against the parser and throws RuntimeException.
        app(YakConfigFiles::class)->concerns($concerns);

        return $concerns;
    }
}
