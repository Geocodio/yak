import assert from 'node:assert/strict';
import { test } from 'node:test';
import { visibleStep } from './steps.ts';

test('keeps the chosen step while it exists', () => {
    assert.equal(visibleStep(1, 3), 1);
});

test('falls back to the last question when a newer round has fewer questions', () => {
    assert.equal(visibleStep(2, 1), 0);
    assert.equal(visibleStep(3, 2), 1);
});
