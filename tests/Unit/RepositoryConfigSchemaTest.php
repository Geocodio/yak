<?php

use App\Services\RepositoryConfigParser;
use App\Services\RepositoryConfigSchemas;

dataset('yak schema files', ['config.yml', 'preview.yml', 'risk-profile.yml']);

function yakSchemaPath(string $file): string
{
    return dirname(__DIR__, 2) . '/schemas/' . basename($file, '.yml') . '.schema.json';
}

/**
 * @param  array<string, mixed>  $schema
 * @return array<string, mixed>
 */
function yakSchemaKeyTree(array $schema): array
{
    $tree = [];
    foreach ($schema['properties'] ?? [] as $key => $child) {
        $tree[$key] = yakSchemaKeyTree($child);
    }
    if (isset($schema['items']['properties'])) {
        $tree['*'] = yakSchemaKeyTree($schema['items']);
    }

    return $tree;
}

it('has a schema whose id is its published url', function (string $file) {
    $schema = json_decode(file_get_contents(yakSchemaPath($file)), true, flags: JSON_THROW_ON_ERROR);

    expect($schema['$id'])->toBe(RepositoryConfigSchemas::url($file))
        ->and($schema['$schema'])->toBe('https://json-schema.org/draft/2020-12/schema')
        ->and($schema['additionalProperties'])->toBeFalse();
})->with('yak schema files');

it('allows exactly the keys the parser allows, at every level', function (string $file) {
    $schema = json_decode(file_get_contents(yakSchemaPath($file)), true, flags: JSON_THROW_ON_ERROR);

    expect(yakSchemaKeyTree($schema))->toEqual(RepositoryConfigParser::keyTree($file));
})->with('yak schema files');

it('matches the parser limits for every bounded value', function () {
    $schema = json_decode(file_get_contents(yakSchemaPath('config.yml')), true, flags: JSON_THROW_ON_ERROR);
    $approval = $schema['properties']['review']['properties']['approval']['properties'];

    expect($approval['max_files'])->toMatchArray(['minimum' => 1, 'maximum' => 100])
        ->and($approval['max_lines'])->toMatchArray(['minimum' => 1, 'maximum' => 5000])
        ->and($approval['max_risk_score'])->toMatchArray(['minimum' => 0, 'maximum' => 30])
        ->and($approval['min_confidence'])->toMatchArray(['minimum' => 80, 'maximum' => 100])
        ->and($approval['mode']['enum'])->toBe(['off', 'shadow', 'enforce'])
        ->and($schema['properties']['ci']['enum'])->toBe(['github_actions', 'drone', 'none'])
        ->and($schema['properties']['co_owner_gate']['properties']['mode']['enum'])->toBe(['off', 'enforce']);
});
