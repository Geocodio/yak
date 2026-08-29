import { test } from 'node:test';
import assert from 'node:assert';
import { readingTimeSeconds, estimatedCutSeconds } from '../../src/v3/readingTime.ts';
import type { Script } from '../../src/v3/types.ts';

test('reading time has a 2.4 s floor', () => {
  assert.strictEqual(readingTimeSeconds('hi'), 2.4);
});

test('reading time is words / 165 wpm plus 0.9 s', () => {
  const words = new Array(33).fill('word').join(' ');
  // 33 / 165 * 60 = 12 s, + 0.9
  assert.ok(Math.abs(readingTimeSeconds(words) - 12.9) < 0.001);
});

test('reading time treats empty text as the floor', () => {
  assert.strictEqual(readingTimeSeconds('   '), 2.4);
});

const script = (shots: number): Script => ({
  version: 3,
  title: 'T',
  intro: 'An intro sentence that says what changed in this pull request.',
  summary: ['One', 'Two'],
  outro: 'Ready for review.',
  shots: Array.from({ length: shots }, (_, i) => ({
    id: `s${i}`,
    chapter: i < shots / 2 ? 'A' : 'B',
    say: 'The page now shows the new section with the warning callout.',
    do: [{ navigate: '/' }],
  })),
  screenshots: [{ id: 'one', caption: 'The new section', after_shot: 's0' }],
});

test('estimated cut length grows with the number of shots', () => {
  const four = estimatedCutSeconds(script(4));
  const eight = estimatedCutSeconds(script(8));
  assert.ok(eight > four, `${eight} should exceed ${four}`);
});

test('a four-shot two-chapter script lands inside the 30-150 s window', () => {
  const seconds = estimatedCutSeconds(script(4));
  assert.ok(seconds >= 30 && seconds <= 150, `expected 30-150 s, got ${seconds}`);
});
