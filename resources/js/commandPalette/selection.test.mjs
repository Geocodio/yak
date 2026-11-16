import assert from 'node:assert/strict';
import { test } from 'node:test';
import { decideSelection, popStep, pushStep } from './selection.ts';

const base = { id: 'a', label: 'A', keywords: [] };
const step = { title: 'T', placeholder: 'p', items: () => [] };

test('an unavailable action is ignored', () => {
    assert.deepEqual(decideSelection({ ...base, unavailable: 'No', run: () => {} }), { kind: 'ignore' });
});

test('an action with confirm asks first', () => {
    const confirm = { title: 'Sure', body: 'b', confirmLabel: 'Go' };
    assert.deepEqual(decideSelection({ ...base, confirm, run: () => {} }), { kind: 'confirm', confirm });
});

test('an action with steps pushes a step and does not run', () => {
    assert.deepEqual(decideSelection({ ...base, steps: step }), { kind: 'step', step });
});

test('a plain action runs', () => {
    assert.deepEqual(decideSelection({ ...base, run: () => {} }), { kind: 'run' });
});

test('push adds on top and pop removes the top; pop on empty is a no-op', () => {
    assert.deepEqual(pushStep([], step), [step]);
    assert.deepEqual(popStep([step]), []);
    assert.deepEqual(popStep([]), []);
});
