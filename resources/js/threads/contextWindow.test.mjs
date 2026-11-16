import assert from 'node:assert/strict';
import { test } from 'node:test';
import { contextMeter, formatTokens, windowFor } from './contextWindow.ts';

const usage = (tokens) => ({ tokens, input: 0, cacheRead: tokens, cacheCreation: 0 });

test('formats token counts', () => {
    assert.equal(formatTokens(950), '950');
    assert.equal(formatTokens(62_400), '62k');
    assert.equal(formatTokens(200_000), '200k');
    assert.equal(formatTokens(1_000_000), '1M');
    assert.equal(formatTokens(1_500_000), '1.5M');
});

test('hides the meter until something is measured', () => {
    assert.equal(contextMeter(null, 200_000), null);
    assert.equal(contextMeter(usage(0), 200_000), null);
});

test('turns amber at 80% and full at 95%', () => {
    assert.deepEqual(contextMeter(usage(62_000), 200_000), { tokens: 62_000, window: 200_000, percent: 31, label: '62k / 200k', tone: 'normal' });
    assert.equal(contextMeter(usage(159_999), 200_000).tone, 'normal');
    assert.equal(contextMeter(usage(160_000), 200_000).tone, 'near');
    assert.equal(contextMeter(usage(190_000), 200_000).tone, 'full');
    assert.equal(contextMeter(usage(250_000), 200_000).percent, 100);
});

test('picks the window of the selected model and falls back to the default', () => {
    const windows = { default: 200_000, sonnet: 1_000_000 };
    assert.equal(windowFor(windows, 'sonnet'), 1_000_000);
    assert.equal(windowFor(windows, 'unknown'), 200_000);
    assert.equal(windowFor(windows, null), 200_000);
});
