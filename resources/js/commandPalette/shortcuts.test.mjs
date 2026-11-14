import assert from 'node:assert/strict';
import { test } from 'node:test';
import { createSequenceMatcher, isTypingTarget, keycapsFor, SHORTCUTS } from './shortcuts.ts';

test('no two entries share the same keys', () => {
    const joined = Object.values(SHORTCUTS).map((shortcut) => shortcut.keys.join('+'));
    assert.equal(new Set(joined).size, joined.length);
});

test('a single key never equals the first key of a sequence', () => {
    const entries = Object.values(SHORTCUTS);
    const firstKeys = entries.filter((shortcut) => shortcut.keys.length > 1 && shortcut.keys[0] !== 'mod').map((shortcut) => shortcut.keys[0]);
    for (const shortcut of entries.filter((entry) => entry.keys.length === 1)) {
        assert.ok(!firstKeys.includes(shortcut.keys[0]), shortcut.keys[0]);
    }
});

test('sequence matcher', () => {
    const matcher = createSequenceMatcher(1000);
    assert.equal(matcher('c', 0), 'newThread');
    assert.equal(matcher('g', 0), null);
    assert.equal(matcher('p', 500), 'openPreview');
    assert.equal(matcher('g', 0), null);
    assert.equal(matcher('p', 2000), null);
    assert.equal(matcher('g', 0), null);
    assert.equal(matcher('x', 100), null);
    assert.equal(matcher('p', 200), null);
    assert.equal(matcher('?', 0), 'showShortcuts');
});

test('isTypingTarget', () => {
    for (const tagName of ['INPUT', 'TEXTAREA', 'SELECT']) {
        assert.equal(isTypingTarget({ tagName }), true);
    }
    assert.equal(isTypingTarget({ tagName: 'DIV', isContentEditable: true }), true);
    assert.equal(isTypingTarget({ tagName: 'BUTTON' }), false);
    assert.equal(isTypingTarget(null), false);
});

test('keycapsFor swaps mod per platform', () => {
    assert.deepEqual(keycapsFor('openPalette', true), ['⌘', 'K']);
    assert.deepEqual(keycapsFor('openPalette', false), ['Ctrl', 'K']);
});
