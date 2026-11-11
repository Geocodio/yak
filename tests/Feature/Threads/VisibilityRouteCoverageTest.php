<?php

use Tests\Support\VisibilityRoutes;

function classifiedRouteNames(): array
{
    return [
        ...array_keys(VisibilityRoutes::probes(VisibilityRoutes::placeholderWorld())),
        ...VisibilityRoutes::global(),
        ...VisibilityRoutes::lists(),
        ...VisibilityRoutes::hosts(),
    ];
}

test('every application route is classified', function () {
    $unclassified = array_values(array_diff(array_keys(VisibilityRoutes::applicationRoutes()), classifiedRouteNames()));

    expect($unclassified)->toBe([], 'classify these in tests/Support/VisibilityRoutes.php: ' . implode(', ', $unclassified));
});

test('no classified name is stale', function () {
    $stale = array_values(array_diff(classifiedRouteNames(), array_keys(VisibilityRoutes::applicationRoutes())));

    expect($stale)->toBe([], 'these no longer exist: ' . implode(', ', $stale));
});

test('no route is classified twice', function () {
    $names = classifiedRouteNames();

    expect(array_values(array_diff_assoc($names, array_unique($names))))->toBe([]);
});
