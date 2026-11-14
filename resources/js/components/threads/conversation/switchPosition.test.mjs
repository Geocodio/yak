import assert from 'node:assert/strict';
import { test } from 'node:test';
import { switchPosition } from './switchPosition.ts';

const person = (sentAt) => ({ type: 'person', message: { sentAt } });
const event = () => ({ type: 'event' });

test('sits before the first message sent after the move', () => {
    const entries = [person('2026-11-14T00:40:00Z'), event(), person('2026-11-14T00:53:00Z'), event()];

    assert.equal(switchPosition(entries, '2026-11-14T00:50:00Z'), 2);
});

test('stays at the end when nothing was sent after the move', () => {
    assert.equal(switchPosition([person('2026-11-14T00:40:00Z'), event()], '2026-11-14T00:50:00Z'), 2);
});
