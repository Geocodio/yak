import assert from 'node:assert/strict';
import { test } from 'node:test';
import { applyCtrl, arrowSequence, ESCAPE, INTERRUPT, TAB } from './keyCodes.ts';

test('sticky ctrl combines with letters', () => {
    assert.equal(applyCtrl('c'), '\x03');
    assert.equal(applyCtrl('C'), '\x03');
    assert.equal(applyCtrl('d'), '\x04');
    assert.equal(applyCtrl('z'), '\x1a');
    assert.equal(applyCtrl('['), '\x1b');
});

test('ctrl does not apply to digits, spaces or pastes', () => {
    assert.equal(applyCtrl('1'), null);
    assert.equal(applyCtrl(' '), null);
    assert.equal(applyCtrl('ls'), null);
});

test('arrows follow the cursor key mode', () => {
    assert.equal(arrowSequence('up', false), '\x1b[A');
    assert.equal(arrowSequence('down', false), '\x1b[B');
    assert.equal(arrowSequence('right', false), '\x1b[C');
    assert.equal(arrowSequence('left', false), '\x1b[D');
    assert.equal(arrowSequence('up', true), '\x1bOA');
});

test('fixed keys', () => {
    assert.equal(ESCAPE, '\x1b');
    assert.equal(TAB, '\t');
    assert.equal(INTERRUPT, '\x03');
});
