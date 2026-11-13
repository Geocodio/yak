import assert from 'node:assert/strict';
import { test } from 'node:test';
import { dockHashFor, parseDockHash, phoneDockIdFor, phoneTabIdFor, phoneTabs } from './dockState.ts';

const ids = (tabs) => tabs.map((tab) => tab.id);

test('phone tabs list all five when preview, files and terminal are registered', () => {
    assert.deepEqual(ids(phoneTabs(['preview', 'files', 'terminal'])), ['chat', 'preview', 'files', 'terminal', 'overview']);
    assert.deepEqual(phoneTabs(['terminal', 'files', 'preview']).map((tab) => tab.label), ['Chat', 'Preview', 'Files', 'Terminal', 'Overview']);
});

test('phone tabs hide panels that are not registered', () => {
    assert.deepEqual(ids(phoneTabs([])), ['chat', 'overview']);
    assert.deepEqual(ids(phoneTabs(['preview'])), ['chat', 'preview', 'overview']);
    assert.deepEqual(ids(phoneTabs(['changes'])), ['chat', 'overview']);
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

test('the retired changes hash opens Files', () => {
    assert.equal(parseDockHash('#panel=changes'), 'files');
});

test('a phone tab shows the dock panel of the same name', () => {
    assert.equal(phoneDockIdFor('terminal'), 'terminal');
    assert.equal(phoneDockIdFor('files'), 'files');
    assert.equal(phoneDockIdFor('preview'), 'preview');
    assert.equal(phoneDockIdFor('chat'), null);
    assert.equal(phoneDockIdFor('overview'), null);
});

test('a dock panel maps back to its phone tab', () => {
    assert.equal(phoneTabIdFor('files'), 'files');
    assert.equal(phoneTabIdFor('terminal'), 'terminal');
    assert.equal(phoneTabIdFor('preview'), 'preview');
    assert.equal(phoneTabIdFor('changes'), 'chat');
    assert.equal(phoneTabIdFor(null), 'chat');
});
