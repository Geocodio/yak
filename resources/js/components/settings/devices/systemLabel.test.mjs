import assert from 'node:assert/strict';
import { test } from 'node:test';
import { systemLabel } from './systemLabel.ts';

test('darwin arm64 reads Apple silicon', () => {
    assert.equal(systemLabel('darwin', 'arm64'), 'macOS, Apple silicon');
});

test('darwin x86_64 keeps the arch', () => {
    assert.equal(systemLabel('darwin', 'x86_64'), 'macOS, x86_64');
});

test('linux and unknown systems', () => {
    assert.equal(systemLabel('linux', 'x86_64'), 'Linux, x86_64');
    assert.equal(systemLabel('freebsd', 'amd64'), 'freebsd, amd64');
});
