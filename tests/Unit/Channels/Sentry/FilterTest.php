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
| Combined Rejection Reason
|--------------------------------------------------------------------------
*/

it('returns null for a normal application error', function () {
    expect(SentryFilter::rejectionReason('app/handler.js', 'TypeError: Cannot read property'))->toBeNull();
});

it('rejects CSP violations', function () {
    expect(SentryFilter::rejectionReason('font-src', 'CSP error'))->toBe('csp_violation');
});

it('rejects transient errors', function () {
    expect(SentryFilter::rejectionReason('', 'RedisException: Connection lost'))->toBe('transient_error');
});

it('CSP rejection has higher priority than transient error', function () {
    expect(SentryFilter::rejectionReason('font-src', 'RedisException'))->toBe('csp_violation');
});

it('Blocked title detected as CSP even when culprit is normal', function () {
    expect(SentryFilter::rejectionReason('app/main.js', 'Blocked inline script execution'))->toBe('csp_violation');
});
