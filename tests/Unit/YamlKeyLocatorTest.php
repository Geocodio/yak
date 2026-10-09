<?php

use App\Support\YamlKeyLocator;

function locatorFixture(): string
{
    return <<<'YAML'
version: 1
review:
  enabled: true
  approval:
    mode: shadow
    max_lines: 150
areas:
  - name: Billing
    paths:
      - app/Billing/**
  - name: Docs
    paths:
      - docs/**
YAML;
}

it('finds a nested key', function () {
    expect(YamlKeyLocator::line(locatorFixture(), 'review.approval.max_lines'))->toBe(6);
});

it('selects list items by number', function () {
    expect(YamlKeyLocator::line(locatorFixture(), 'areas.1.name'))->toBe(11)
        ->and(YamlKeyLocator::line(locatorFixture(), 'areas.0.paths.0'))->toBe(10)
        ->and(YamlKeyLocator::line(locatorFixture(), 'areas.1.paths'))->toBe(12);
});

it('returns line 1 for a missing key', function () {
    expect(YamlKeyLocator::line(locatorFixture(), 'nope.key'))->toBe(1)
        ->and(YamlKeyLocator::line(locatorFixture(), 'review.approval.nope'))->toBe(1)
        ->and(YamlKeyLocator::line(locatorFixture(), 'areas.5.name'))->toBe(1);
});
