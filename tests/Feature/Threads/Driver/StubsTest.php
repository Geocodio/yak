<?php

use App\Support\SecretRedactor;

require_once __DIR__ . '/../../../Support/NextGenStubs.php';

it('lets the redactor hide secrets', function () {
    expect(SecretRedactor::redact('x sk-ant-abc123'))->toBe('x [redacted]')
        ->and(SecretRedactor::redact('y yakph-AbC_9'))->toBe('y [redacted]');
});
