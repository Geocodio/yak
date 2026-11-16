import assert from 'node:assert/strict';
import { test } from 'node:test';
import { resolveFilesMode } from './filesMode.ts';

const diff = (filesCount, throwawayFilesCount = 0) => ({ filesCount, throwawayFilesCount });

test('an explicit request wins', () => {
    assert.equal(resolveFilesMode('everything', diff(3), false), 'everything');
    assert.equal(resolveFilesMode('changed', null, true), 'changed');
});

test('the default is Changed when there are changes and Everything when there are none', () => {
    assert.equal(resolveFilesMode(null, diff(2), false), 'changed');
    assert.equal(resolveFilesMode(null, diff(0, 1), false), 'changed');
    assert.equal(resolveFilesMode(null, diff(0), false), 'everything');
    assert.equal(resolveFilesMode(null, null, false), 'everything');
});

test('the default waits for the diff to load', () => {
    assert.equal(resolveFilesMode(null, null, true), null);
});
