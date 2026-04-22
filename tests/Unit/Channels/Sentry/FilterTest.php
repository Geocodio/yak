<?php

use App\Channels\Sentry\Filter as SentryFilter;

/*
|--------------------------------------------------------------------------
| CSP Violation Detection
|--------------------------------------------------------------------------
*/

it('detects CSP violation from culprit containing font-src', function () {
    expect(SentryFilter::isCSPViolation('font-src', 'Some error'))->toBeTrue();
});

it('detects CSP violation from culprit containing script-src-elem', function () {
    expect(SentryFilter::isCSPViolation('script-src-elem', 'Some error'))->toBeTrue();
});

it('detects CSP violation from culprit containing style-src-elem', function () {
    expect(SentryFilter::isCSPViolation('style-src-elem', 'Some error'))->toBeTrue();
});

it('detects CSP violation from culprit containing connect-src', function () {
    expect(SentryFilter::isCSPViolation('connect-src', 'Some error'))->toBeTrue();
});

it('detects CSP violation from culprit containing default-src', function () {
    expect(SentryFilter::isCSPViolation('default-src', 'Some error'))->toBeTrue();
});

it('detects CSP violation from title starting with Blocked', function () {
    expect(SentryFilter::isCSPViolation('app/handler.js', 'Blocked inline script'))->toBeTrue();
});

it('does not flag normal errors as CSP violations', function () {
    expect(SentryFilter::isCSPViolation('app/handler.js', 'TypeError: Cannot read property'))->toBeFalse();
});

it('CSP culprit check is case insensitive', function () {
    expect(SentryFilter::isCSPViolation('FONT-SRC', 'Some error'))->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Transient Infrastructure Error Detection
|--------------------------------------------------------------------------
*/

it('detects RedisException as transient error', function () {
    expect(SentryFilter::isTransientError('', 'RedisException: Connection lost'))->toBeTrue();
});

it('detects Predis exception as transient error', function () {
    expect(SentryFilter::isTransientError('Predis\\Connection\\ConnectionException', 'Connection error'))->toBeTrue();
});

it('detects php_network_getaddresses as transient error', function () {
    expect(SentryFilter::isTransientError('', 'php_network_getaddresses: getaddrinfo failed'))->toBeTrue();
});

it('detects context deadline exceeded as transient error', function () {
    expect(SentryFilter::isTransientError('', 'context deadline exceeded'))->toBeTrue();
});

it('detects Connection refused as transient error', function () {
    expect(SentryFilter::isTransientError('', 'Connection refused'))->toBeTrue();
});

it('detects Operation timed out as transient error', function () {
    expect(SentryFilter::isTransientError('', 'Operation timed out'))->toBeTrue();
});

it('does not flag normal errors as transient', function () {
    expect(SentryFilter::isTransientError('app/handler.js', 'TypeError: Cannot read property'))->toBeFalse();
});

it('detects transient error in culprit field', function () {
    expect(SentryFilter::isTransientError('RedisException', 'Some title'))->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Actionability Threshold
|--------------------------------------------------------------------------
*/

it('accepts high actionability when minimum is medium', function () {
    expect(SentryFilter::meetsActionability('high', 'medium'))->toBeTrue();
});

it('accepts medium actionability when minimum is medium', function () {
    expect(SentryFilter::meetsActionability('medium', 'medium'))->toBeTrue();
});

it('rejects low actionability when minimum is medium', function () {
    expect(SentryFilter::meetsActionability('low', 'medium'))->toBeFalse();
});

it('rejects not_actionable when minimum is medium', function () {
    expect(SentryFilter::meetsActionability('not_actionable', 'medium'))->toBeFalse();
});

it('treats unknown actionability as not_actionable', function () {
    expect(SentryFilter::meetsActionability('unknown', 'medium'))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Event Count Threshold
|--------------------------------------------------------------------------
*/

it('accepts event count at threshold', function () {
    expect(SentryFilter::meetsEventCount(5, 5))->toBeTrue();
});

it('accepts event count above threshold', function () {
    expect(SentryFilter::meetsEventCount(10, 5))->toBeTrue();
});

it('rejects event count below threshold', function () {
    expect(SentryFilter::meetsEventCount(4, 5))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Combined Rejection Reason
|--------------------------------------------------------------------------
*/

it('returns null for valid actionable issue with enough events', function () {
    $reason = SentryFilter::rejectionReason(
        culprit: 'app/handler.js',
        title: 'TypeError: Cannot read property',
        actionability: 'high',
        eventCount: 10,
        hasPriorityTag: false,
    );

    expect($reason)->toBeNull();
});

it('rejects CSP violations even with priority tag', function () {
    $reason = SentryFilter::rejectionReason(
        culprit: 'font-src',
        title: 'CSP error',
        actionability: 'high',
        eventCount: 100,
        hasPriorityTag: true,
    );

    expect($reason)->toBe('csp_violation');
});

it('rejects transient errors even with priority tag', function () {
    $reason = SentryFilter::rejectionReason(
        culprit: '',
        title: 'RedisException: Connection lost',
        actionability: 'high',
        eventCount: 100,
        hasPriorityTag: true,
    );

    expect($reason)->toBe('transient_error');
});

it('rejects low actionability without priority tag', function () {
    $reason = SentryFilter::rejectionReason(
        culprit: 'app/handler.js',
        title: 'Some error',
        actionability: 'low',
        eventCount: 10,
        hasPriorityTag: false,
    );

    expect($reason)->toBe('low_actionability');
});

it('rejects low event count without priority tag', function () {
    $reason = SentryFilter::rejectionReason(
        culprit: 'app/handler.js',
        title: 'Some error',
        actionability: 'high',
        eventCount: 3,
        hasPriorityTag: false,
    );

    expect($reason)->toBe('low_event_count');
});

it('priority tag bypasses event count filter', function () {
    $reason = SentryFilter::rejectionReason(
        culprit: 'app/handler.js',
        title: 'Some error',
        actionability: 'high',
        eventCount: 1,
        hasPriorityTag: true,
    );

    expect($reason)->toBeNull();
});

it('priority tag bypasses actionability filter', function () {
    $reason = SentryFilter::rejectionReason(
        culprit: 'app/handler.js',
        title: 'Some error',
        actionability: 'low',
        eventCount: 1,
        hasPriorityTag: true,
    );

    expect($reason)->toBeNull();
});

it('uses custom min_actionability threshold', function () {
    $reason = SentryFilter::rejectionReason(
        culprit: 'app/handler.js',
        title: 'Some error',
        actionability: 'low',
        eventCount: 10,
        hasPriorityTag: false,
        minActionability: 'low',
    );

    expect($reason)->toBeNull();
});

it('uses custom min_events threshold', function () {
    $reason = SentryFilter::rejectionReason(
        culprit: 'app/handler.js',
        title: 'Some error',
        actionability: 'high',
        eventCount: 2,
        hasPriorityTag: false,
        minEvents: 2,
    );

    expect($reason)->toBeNull();
});

it('CSP rejection has higher priority than transient error', function () {
    $reason = SentryFilter::rejectionReason(
        culprit: 'font-src',
        title: 'RedisException',
        actionability: 'high',
        eventCount: 100,
        hasPriorityTag: false,
    );

    expect($reason)->toBe('csp_violation');
});

it('Blocked title detected as CSP even when culprit is normal', function () {
    $reason = SentryFilter::rejectionReason(
        culprit: 'app/main.js',
        title: 'Blocked inline script execution',
        actionability: 'high',
        eventCount: 100,
        hasPriorityTag: false,
    );

    expect($reason)->toBe('csp_violation');
});
