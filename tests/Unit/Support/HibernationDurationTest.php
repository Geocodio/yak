<?php

use App\Support\HibernationDuration;

it('parses single-unit durations to minutes', function () {
    expect(HibernationDuration::toMinutes('30m'))->toBe(30);
    expect(HibernationDuration::toMinutes('3h'))->toBe(180);
    expect(HibernationDuration::toMinutes('2d'))->toBe(2880);
    expect(HibernationDuration::toMinutes('5w'))->toBe(50400);
});

it('parses compound durations', function () {
    expect(HibernationDuration::toMinutes('1w2d'))->toBe(12960);
    expect(HibernationDuration::toMinutes('1d12h'))->toBe(2160);
});

it('is whitespace and case tolerant', function () {
    expect(HibernationDuration::toMinutes('  3D '))->toBe(4320);
});

it('returns null for invalid or empty input', function () {
    expect(HibernationDuration::toMinutes(''))->toBeNull();
    expect(HibernationDuration::toMinutes('nonsense'))->toBeNull();
    expect(HibernationDuration::toMinutes('3'))->toBeNull();
    expect(HibernationDuration::toMinutes('3x'))->toBeNull();
    expect(HibernationDuration::toMinutes('0m'))->toBeNull();
});

it('renders minutes to a human string', function () {
    expect(HibernationDuration::humanize(4320))->toBe('3 days');
    expect(HibernationDuration::humanize(720))->toBe('12 hours');
    expect(HibernationDuration::humanize(10080))->toBe('1 week');
    expect(HibernationDuration::humanize(90))->toBe('90 minutes');
});

it('renders minutes to shorthand', function () {
    expect(HibernationDuration::toShorthand(4320))->toBe('3d');
    expect(HibernationDuration::toShorthand(720))->toBe('12h');
    expect(HibernationDuration::toShorthand(20160))->toBe('2w');
    expect(HibernationDuration::toShorthand(15))->toBe('15m');
});
