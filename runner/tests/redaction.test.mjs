import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createRedactor } from '../lib/redaction.mjs';
import { SECRET } from './helpers/fake-sdk.mjs';

const delta = (index, text) => ({ type: 'stream_event', event: { type: 'content_block_delta', index, delta: { type: 'text_delta', text } } });

test('it redacts a secret anywhere in a line', () => {
  const { redactText } = createRedactor([SECRET]);
  assert.equal(redactText(`a ${SECRET} b ${SECRET}`), 'a [redacted] b [redacted]');
});

test('it redacts a secret split across partial deltas without delaying other text', () => {
  const redactor = createRedactor([SECRET]);
  const half = SECRET.length >> 1;
  const out = [delta(0, 'key is '), delta(0, SECRET.slice(0, half)), delta(0, SECRET.slice(half) + ' done')]
    .map((message) => redactor.withholdDelta(message).event.delta.text);
  assert.deepEqual(out, ['key is ', '', '[redacted] done']);
});

test('it flushes held text on content_block_stop and message_stop', () => {
  const redactor = createRedactor([SECRET]);
  redactor.withholdDelta(delta(1, SECRET.slice(0, 4)));
  redactor.withholdDelta(delta(2, SECRET.slice(0, 3)));
  const stop = { type: 'stream_event', event: { type: 'content_block_stop', index: 1 } };
  const [one] = redactor.flushHeld(stop, 1);
  assert.equal(one.event.delta.text, SECRET.slice(0, 4));
  assert.equal(one.event.index, 1);
  const rest = redactor.flushHeld({ type: 'stream_event', event: { type: 'message_stop' } });
  assert.equal(rest.length, 1);
  assert.equal(rest[0].event.delta.text, SECRET.slice(0, 3));
  assert.deepEqual(redactor.flushHeld(stop), []);
});
