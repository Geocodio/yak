import assert from 'node:assert/strict';
import { test } from 'node:test';
import { followStep } from './followStep.ts';

test('closes part of the gap each frame', () => {
    const next = followStep(0, 1000, 1 / 60);

    assert.ok(next > 0 && next < 1000);
    assert.ok(Math.abs(next - 1000 * (1 - Math.exp(-10 / 60))) < 1e-6);
});

test('moves at least one pixel', () => {
    assert.equal(followStep(100, 103, 1 / 60), 101);
});

test('never passes the target', () => {
    assert.equal(followStep(100, 100.4, 1 / 60), 100.4);
    assert.equal(followStep(0, 5, 10), 5);
});
