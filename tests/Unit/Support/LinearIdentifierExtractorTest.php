<?php

use App\Support\LinearIdentifierExtractor;

it('extracts a ticket id from body text', function () {
    expect(LinearIdentifierExtractor::firstFrom('Fixes GEO-1234.'))->toBe('GEO-1234');
});

it('extracts a ticket id from a linear.app URL', function () {
    expect(LinearIdentifierExtractor::firstFrom('See https://linear.app/geocodio/issue/GEO-42/foo'))->toBe('GEO-42');
});

it('returns null when no identifier is present', function () {
    expect(LinearIdentifierExtractor::firstFrom('No ticket here'))->toBeNull();
});

it('does not match lowercase or malformed ids', function () {
    expect(LinearIdentifierExtractor::firstFrom('geo-1234'))->toBeNull();
    expect(LinearIdentifierExtractor::firstFrom('GEO1234'))->toBeNull();
});
