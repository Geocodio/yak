import assert from 'node:assert/strict';
import { readdirSync, readFileSync } from 'node:fs';
import { test } from 'node:test';
import { APP_MESSAGE_TYPES, PAGE_MESSAGE_TYPES, sameOriginUrl, validateAppMessage, validatePageMessage } from './bridge.ts';
import { cardActionOf } from './cardActions.ts';
import { visibleOutbox } from './outboxSource.ts';

const FIXTURES = new URL('../../../tests/Fixtures/app-api/v1/embed-bridge/', import.meta.url);
const fixtures = readdirSync(FIXTURES).filter((name) => name.endsWith('.json')).map((name) => ({ name, message: JSON.parse(readFileSync(new URL(name, FIXTURES), 'utf8')) }));

test('it ignores unknown types', () => {
    assert.deepEqual(validateAppMessage({ v: 1, type: 'somethingNew', anything: 1 }), { kind: 'unknown' });
    assert.deepEqual(validatePageMessage({ v: 1, type: 'toString' }), { kind: 'unknown' });
    assert.deepEqual(validateAppMessage({ v: 1, type: 'ready', threadId: 'd0e515ef' }), { kind: 'unknown' });
});

test('it rejects malformed payloads', () => {
    for (const raw of [null, 'open', [], { type: 'refresh' }, { v: 2, type: 'refresh' }]) {
        assert.equal(validateAppMessage(raw).kind, 'malformed');
    }
    assert.equal(validateAppMessage({ v: 1, type: 'open', threadId: '../etc' }).kind, 'malformed');
    assert.equal(validateAppMessage({ v: 1, type: 'focus', fragment: 'a b' }).kind, 'malformed');
    assert.equal(validateAppMessage({ v: 1, type: 'connectivity', online: 'yes' }).kind, 'malformed');
    assert.equal(validateAppMessage({ v: 1, type: 'outbox', threadId: 'd0e515ef', items: [{ key: 'k' }] }).kind, 'malformed');
    assert.equal(validatePageMessage({ v: 1, type: 'openPane', pane: 'files', mode: 'sideways' }).kind, 'malformed');
    assert.equal(validatePageMessage({ v: 1, type: 'cardAnswered', card: 'nope', action: 'answer', ok: true }).kind, 'malformed');
    assert.equal(validatePageMessage({ v: 1, type: 'ready', threadId: 'd0e515ef', url: '/x', latestSequence: -1, title: '' }).kind, 'malformed');
});

test('it drops an outbox row once messages carry its sendKey', () => {
    const item = (key) => ({ key, text: key, status: 'waiting', reason: null, createdAt: '2026-11-20T10:15:00Z', attachmentCount: 0 });
    const items = [item('a'), item('b')];

    assert.equal(visibleOutbox(items, [{ sendKey: null }, { sendKey: 'x' }]), items);
    assert.deepEqual(visibleOutbox(items, [{ sendKey: 'a' }]).map((row) => row.key), ['b']);
    assert.deepEqual(visibleOutbox(items, [{ sendKey: 'b' }, { sendKey: 'a' }]), []);
});

test('every fixture validates against the bridge and every type has one', () => {
    for (const { name, message } of fixtures) {
        const result = name.startsWith('page-') ? validatePageMessage(message) : validateAppMessage(message);
        assert.equal(result.kind, 'ok', name);
    }
    for (const type of PAGE_MESSAGE_TYPES) {
        assert.ok(fixtures.some(({ name, message }) => name.startsWith('page-') && message.type === type), `page-${type}`);
    }
    for (const type of APP_MESSAGE_TYPES) {
        assert.ok(fixtures.some(({ name, message }) => name.startsWith('app-') && message.type === type), `app-${type}`);
    }
});

test('a ready message names a thread with its feed position, or neither', () => {
    const ready = { v: 1, type: 'ready', url: '/app/embed/deployments', title: 'Deployments' };

    assert.equal(validatePageMessage(ready).kind, 'ok');
    assert.equal(validatePageMessage({ ...ready, threadId: 'd0e515ef', latestSequence: 3 }).kind, 'ok');
    assert.equal(validatePageMessage({ ...ready, threadId: 'd0e515ef' }).kind, 'malformed');
    assert.equal(validatePageMessage({ ...ready, latestSequence: 3 }).kind, 'malformed');
});

test('it names the card action of a post', () => {
    assert.equal(cardActionOf('POST', '/threads/d0e515ef/clarification-answers'), 'answer');
    assert.equal(cardActionOf('post', '/app/embed/threads/d0e515ef/co-owner/approve'), 'approve');
    assert.equal(cardActionOf('post', '/threads/d0e515ef/restore/retry'), 'restore-retry');
    assert.equal(cardActionOf('get', '/threads/d0e515ef/retry'), null);
    assert.equal(cardActionOf('post', '/threads/d0e515ef/messages'), null);
});

test('only same-origin urls go to the quick look', () => {
    const base = 'https://yak.example/app/embed/threads/abcd1234';

    assert.equal(sameOriginUrl('/threads/a/files/1', base), 'https://yak.example/threads/a/files/1');
    assert.equal(sameOriginUrl('https://yak.example/x', base), 'https://yak.example/x');
    assert.equal(sameOriginUrl('https://other.example/x', base), null);
    assert.equal(sameOriginUrl('//other.example/x', base), null);
});

test('a threadChanged without the previous thread is malformed', () => {
    assert.equal(validatePageMessage({ v: 1, type: 'threadChanged', threadId: 'd0e515ef', previousThreadId: null }).kind, 'malformed');
});
