import assert from 'node:assert/strict';
import { test } from 'node:test';
import { formatWhen } from './format.ts';

const now = new Date(2026, 10, 17, 15, 0);
const at = (year, month, day, hour, minute) => new Date(year, month, day, hour, minute).toISOString();

test('today shows the clock time only', () => {
    assert.equal(formatWhen(at(2026, 10, 17, 10, 37), now), '10:37 AM');
});

test('yesterday is named', () => {
    assert.equal(formatWhen(at(2026, 10, 16, 16, 12), now), 'Yesterday 4:12 PM');
});

test('an older date this year shows month and day', () => {
    assert.equal(formatWhen(at(2026, 10, 3, 16, 12), now), 'Nov 3 4:12 PM');
});

test('a date in another year shows the year', () => {
    assert.equal(formatWhen(at(2025, 10, 3, 16, 12), now), 'Nov 3, 2025 4:12 PM');
});
