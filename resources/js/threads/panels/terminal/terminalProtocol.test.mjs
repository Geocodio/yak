import assert from 'node:assert/strict';
import { test } from 'node:test';
import { backoffDelay, classifyClose, decodeControl, encodeResize, refusalReason } from './terminalProtocol.ts';

test('backoff doubles then holds at 8 seconds', () => {
    assert.deepEqual([0, 1, 2, 3, 4].map(backoffDelay), [1000, 2000, 4000, 8000, 8000]);
});

test('4403 and 1009 never reconnect, 1000 finishes, others retry', () => {
    assert.equal(classifyClose(4403), 'refused');
    assert.equal(classifyClose(1009), 'refused');
    assert.equal(classifyClose(1000), 'finished');
    assert.equal(classifyClose(4429), 'too_many');
    assert.equal(classifyClose(1006), 'retry');
    assert.equal(classifyClose(4408), 'retry');
});

test('resize encodes and clamps', () => {
    assert.deepEqual(JSON.parse(encodeResize(80, 24)), { type: 'resize', cols: 80, rows: 24 });
    assert.deepEqual(JSON.parse(encodeResize(0, 9999)), { type: 'resize', cols: 1, rows: 500 });
});

test('control decoding', () => {
    assert.deepEqual(decodeControl('{"type":"exit","code":2}'), { type: 'exit', code: 2 });
    assert.deepEqual(decodeControl('nope'), { type: 'unknown' });
    assert.deepEqual(decodeControl('{"type":"x"}'), { type: 'unknown' });
});

test('refusal reasons', () => {
    assert.match(refusalReason(409, 'thread_closed'), /closed/);
    assert.match(refusalReason(404, null), /not be found/);
});
