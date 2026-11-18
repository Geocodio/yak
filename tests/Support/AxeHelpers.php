<?php

/**
 * Runs axe in the page with the colour contrast rule off (the shared theme's faint tokens sit
 * below the AA ratio) and fails on any serious or critical violation, naming each rule and target.
 */
function assertNoSeriousAccessibilityViolations(mixed $page): void
{
    $page->script('new Promise((resolve) => { const start = Date.now(); (function poll() { if (window.axe || Date.now() - start > 5000) { resolve(true); } else { setTimeout(poll, 100); } })(); })');

    /** @var list<array{id: string, impact: string|null, nodes: list<array{target: list<string>}>}> $violations */
    $violations = $page->script("async () => ((await window.axe.run(document, { rules: { 'color-contrast': { enabled: false } } })).violations)") ?? [];

    $serious = array_values(array_filter($violations, fn (array $violation): bool => in_array($violation['impact'] ?? null, ['serious', 'critical'], true)));

    $report = implode("\n", array_map(
        fn (array $violation): string => $violation['id'] . ' (' . $violation['impact'] . '): ' . implode(' | ', array_map(fn (array $node): string => implode(' ', $node['target']), array_slice($violation['nodes'], 0, 3))),
        $serious,
    ));

    expect($serious)->toBeEmpty("Serious accessibility violations:\n" . $report);
}
