import assert from 'node:assert/strict';
import { test } from 'node:test';
import { findModel, modelLabel } from './accountAppearance.ts';

const models = [
    { family: 'opus', latest: { value: 'opus', label: 'Opus 5.5', description: 'For complex work' }, older: [{ value: 'claude-opus-5', label: 'Opus 5' }] },
    { family: 'haiku', latest: { value: 'haiku', label: 'Haiku 5.5', description: 'Fast' }, older: [] },
];

test('an alias reads as its current version and an exact id as that version', () => {
    assert.equal(modelLabel('opus', models), 'Opus 5.5');
    assert.equal(modelLabel('claude-opus-5', models), 'Opus 5');
});

test('unknown values keep their raw text and null reads as Default', () => {
    assert.equal(modelLabel('claude-mystery-1', models), 'claude-mystery-1');
    assert.equal(modelLabel(null, models), 'Default');
});

test('only an older exact id counts as pinned', () => {
    assert.deepEqual(findModel(models, 'haiku'), { label: 'Haiku 5.5', isPinned: false });
    assert.deepEqual(findModel(models, 'claude-opus-5'), { label: 'Opus 5', isPinned: true });
    assert.equal(findModel(models, 'nope'), null);
});
