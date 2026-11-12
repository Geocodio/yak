<?php

use App\Support\SecretRedactor;

it('lets the redactor hide secrets', function () {
    expect(SecretRedactor::redact('x sk-ant-abc12345'))->toBe('x [redacted]')
        ->and(SecretRedactor::redact('y yakph-AbC_9AbC_9AbC_9AbC_9AbC_9'))->toBe('y [redacted]');
});
