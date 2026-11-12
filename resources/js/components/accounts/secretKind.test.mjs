import assert from 'node:assert/strict';
import { test } from 'node:test';
import { cleanSecret, detectKind } from './secretKind.ts';

test('cleanSecret removes spaces and line breaks', () => {
    assert.equal(cleanSecret('  sk-ant-oat01-abc\n def\r\n\tghi  '), 'sk-ant-oat01-abcdefghi');
    assert.equal(cleanSecret(''), '');
});

test('detectKind', () => {
    assert.equal(detectKind('sk-ant-oat01-abc'), 'setup_token');
    assert.equal(detectKind('sk-ant-api03-abc'), 'api_key');
    assert.equal(detectKind('hello'), null);
    assert.equal(detectKind(''), null);
});
