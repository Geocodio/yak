import assert from 'node:assert/strict';
import { test } from 'node:test';
import { dockHashFor, parseDockHash, phoneDockIdFor, phoneTabIdFor, phoneTabs } from './dockState.ts';

const ids = (tabs) => tabs.map((tab) => tab.id);

test('phone tabs list all five when preview, changes and terminal are registered', () => {
    assert.deepEqual(ids(phoneTabs(['preview', 'changes', 'terminal'])), ['chat', 'preview', 'changes', 'terminal', 'overview']);
    assert.deepEqual(phoneTabs(['terminal', 'changes', 'preview']).map((tab) => tab.label), ['Chat', 'Preview', 'Changes', 'Terminal', 'Overview']);
});

test('phone tabs hide panels that are not registered', () => {
    assert.deepEqual(ids(phoneTabs([])), ['chat', 'overview']);
    assert.deepEqual(ids(phoneTabs(['preview'])), ['chat', 'preview', 'overview']);
    assert.deepEqual(ids(phoneTabs(['files'])), ['chat', 'overview']);
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

test('the phone Terminal tab shows the shell or the files dock panel', () => {
    assert.equal(phoneDockIdFor('terminal', 'shell'), 'terminal');
    assert.equal(phoneDockIdFor('terminal', 'files'), 'files');
    assert.equal(phoneDockIdFor('preview', 'files'), 'preview');
    assert.equal(phoneDockIdFor('chat', 'shell'), null);
    assert.equal(phoneDockIdFor('overview', 'files'), null);
    assert.equal(phoneDockIdFor('changes', 'shell'), 'changes');
});

test('a dock panel maps back to its phone tab', () => {
    assert.equal(phoneTabIdFor('changes'), 'changes');
    assert.equal(phoneTabIdFor('files'), 'terminal');
    assert.equal(phoneTabIdFor('preview'), 'preview');
    assert.equal(phoneTabIdFor(null), 'chat');
});
