import assert from 'node:assert/strict';
import { test } from 'node:test';
import { initialFeed, reduceFeed } from '../../../threads/realtime/feedReducer.ts';
import { mergeConversation } from './mergeConversation.ts';
import { projectConversation } from './projectConversation.ts';

const at = '2026-11-11T00:00:00Z';
const entry = (sequence, data) => ({ sequence, type: 'entry', createdAt: at, data });
const assistant = (sequence, messageId, blocks) => entry(sequence, { role: 'assistant', messageId, blocks });
const toolResult = (sequence, toolUseId, text) => entry(sequence, { role: 'tool_result', toolUseId, isError: false, text });
const use = (id, name, input = {}) => ({ type: 'tool_use', id, name, input });
const feedOf = (...events) => reduceFeed(initialFeed(), { kind: 'events', events });
const project = (feed) => projectConversation(feed.state, feed.entrySequences);

test('empty state projects nothing', () => {
    assert.deepEqual(project(initialFeed()), { items: [], readSequences: {} });
});

test('text and thinking blocks are keyed by message and block index', () => {
    const { items } = project(feedOf(assistant(1, 'm1', [{ type: 'thinking', text: 'hm' }, { type: 'text', text: 'hello' }])));

    assert.deepEqual(items.map((item) => [item.kind, item.key, item.sequence, item.text]), [
        ['thinking', 'm1:0', 1, 'hm'],
        ['text', 'm1:1', 1, 'hello'],
    ]);
});

test('consecutive tool uses become one tools item with joined results', () => {
    const { items } = project(
        feedOf(assistant(1, 'm1', [use('a', 'Read'), use('b', 'Read')]), toolResult(2, 'a', 'file a'), toolResult(3, 'b', 'file b')),
    );

    assert.equal(items.length, 1);
    assert.equal(items[0].kind, 'tools');
    assert.equal(items[0].key, 'tools:m1:0');
    assert.equal(items[0].text, 'Read 2 files');
    assert.equal(items[0].detail, 'file a\n\nfile b');
});

test('Edit, MultiEdit and Write fold into one edits item', () => {
    const { items } = project(
        feedOf(
            assistant(1, 'm1', [
                use('a', 'Edit', { file_path: 'a.php', old_string: 'x', new_string: 'y\nz' }),
                use('b', 'Write', { file_path: 'b.php', content: '1\n2\n3' }),
                use('c', 'MultiEdit', { file_path: 'a.php', edits: [{ old_string: 'p\nq', new_string: 'r' }] }),
            ]),
        ),
    );

    assert.equal(items.length, 1);
    assert.deepEqual(items[0], { kind: 'edits', key: 'edits:m1', sequence: 1, role: 'assistant', files: ['a.php', 'b.php'], added: 6, removed: 3 });
});

test('every result entry keeps its own item', () => {
    const result = (text) => ({ role: 'result', subtype: 'success', isError: false, text, costUsd: 0, durationMs: 0, numTurns: 1 });
    const { items } = project(feedOf(entry(4, result('one')), entry(7, result('two'))));

    assert.deepEqual(items.map((item) => item.key), ['result:4', 'result:7']);
});

test('a user entry with a uuid only yields a read sequence', () => {
    const { items, readSequences } = project(feedOf(entry(5, { role: 'user', uuid: 'u1', kind: 'prompt', text: 'hi' }), entry(6, { role: 'user', kind: 'steer', text: 'x' })));

    assert.deepEqual(items, []);
    assert.deepEqual(readSequences, { u1: 5 });
});

test('streaming text becomes a trailing item that a stored block replaces after merge; tool_input is skipped', () => {
    const delta = (sequence, blockKey, kind, text) => ({ sequence, type: 'delta', createdAt: at, data: { blockKey, kind, text } });
    const streaming = project(feedOf(delta(1, 'm1:0', 'text', 'hel'), delta(2, 'm1:1', 'tool_input', '{}')));

    assert.deepEqual(streaming.items.map((item) => [item.key, item.sequence]), [['m1:0', 3]]);

    const stored = project(feedOf(assistant(3, 'm1', [{ type: 'text', text: 'hello' }])));
    const merged = mergeConversation([], [], [...streaming.items, ...stored.items]);

    assert.equal(merged.length, 1);
    assert.equal(merged[0].item.text, 'hello');
});

test('replay yields identical keys and merge never duplicates tools or edits', () => {
    const events = [
        assistant(1, 'm1', [{ type: 'text', text: 'a' }, use('t', 'Read'), use('e', 'Edit', { file_path: 'f', old_string: 'a', new_string: 'b' })]),
        toolResult(2, 't', 'out'),
    ];
    const first = project(feedOf(...events));
    const redelivered = project(reduceFeed(feedOf(...events), { kind: 'events', events: [events[0], events[1]] }));
    const reprojected = project(feedOf(...events));
    const keys = (projection) => projection.items.map((item) => item.key);

    assert.deepEqual(keys(first), ['m1:0', 'tools:m1:1', 'edits:m1']);
    assert.deepEqual(keys(redelivered), keys(first));
    assert.deepEqual(keys(reprojected), keys(first));

    const merged = mergeConversation([], [], [...first.items, ...redelivered.items, ...reprojected.items]);

    assert.deepEqual(merged.map((entry) => entry.item.key), keys(first));
});

const childEvent = (sequence, childThreadId, state, extra = {}) => ({
    sequence,
    type: 'child',
    createdAt: at,
    data: { childThreadId, name: `child-${childThreadId}`, kind: 'implement', model: 'sonnet', state, step: null, resultSummary: null, branch: null, messagesPaused: false, ...extra },
});

test('projects the latest child state into one card at its first position', () => {
    const { items } = project(
        feedOf(assistant(1, 'm1', [{ type: 'text', text: 'hi' }]), childEvent(2, 7, 'queued'), assistant(3, 'm2', [{ type: 'text', text: 'later' }]), childEvent(4, 7, 'working', { step: 'Grep' })),
    );

    const ordered = mergeConversation([], [], items).map((entry) => entry.item);
    assert.deepEqual(ordered.map((item) => item.key), ['m1:0', 'child:7', 'm2:0']);
    const card = items.find((item) => item.kind === 'child');
    assert.equal(card.sequence, 2);
    assert.equal(card.child.state, 'working');
    assert.equal(card.child.step, 'Grep');
});

test('keeps one card per child', () => {
    const { items } = project(feedOf(childEvent(1, 7, 'queued'), childEvent(2, 8, 'queued')));

    assert.deepEqual(items.map((item) => item.key), ['child:7', 'child:8']);
});
