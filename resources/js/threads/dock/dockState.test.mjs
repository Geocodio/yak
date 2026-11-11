import assert from 'node:assert/strict';
import { test } from 'node:test';
import { dockHashFor, parseDockHash, phoneTabs } from './dockState.ts';

const ids = (tabs) => tabs.map((tab) => tab.id);

test('phone tabs list all four when preview and terminal are registered', () => {
    assert.deepEqual(ids(phoneTabs(['preview', 'terminal'])), ['chat', 'preview', 'terminal', 'details']);
    assert.deepEqual(phoneTabs(['terminal', 'preview']).map((tab) => tab.label), ['Chat', 'Preview', 'Terminal', 'Details']);
});

test('phone tabs hide panels that are not registered', () => {
    assert.deepEqual(ids(phoneTabs([])), ['chat', 'details']);
    assert.deepEqual(ids(phoneTabs(['preview'])), ['chat', 'preview', 'details']);
    assert.deepEqual(ids(phoneTabs(['files'])), ['chat', 'details']);
});

test('the dock hash names the active panel', () => {
    assert.equal(parseDockHash('#panel=preview'), 'preview');
    assert.equal(parseDockHash('#panel=preview-2'), 'preview-2');
    assert.equal(parseDockHash(''), null);
    assert.equal(parseDockHash('#other'), null);
    assert.equal(parseDockHash('#panel='), null);
    assert.equal(parseDockHash('#panel=<script>'), null);
    assert.equal(dockHashFor('preview'), '#panel=preview');
    assert.equal(dockHashFor(null), '');
});
