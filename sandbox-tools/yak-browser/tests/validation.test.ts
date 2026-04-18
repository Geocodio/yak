import { test } from 'node:test';
import assert from 'node:assert';
import { validatePlan } from '../src/lib/validation.ts';

const validReviewer = () => ({
  tier: 'reviewer',
  goal: 'Demo',
  chapters: [
    { title: 'Intro', beats: ['show page'] },
    { title: 'Action', beats: ['click button'] },
    { title: 'Result', beats: ['see result'] },
  ],
  expected_duration_seconds: 45,
  emphasize_budget: 2,
  callout_budget: 1,
  fastforward_segments: [],
});

test('valid reviewer plan passes', () => {
  const result = validatePlan(validReviewer());
  assert.strictEqual(result.ok, true);
});

test('rejects tier outside reviewer/director', () => {
  const p: any = validReviewer();
  p.tier = 'pro';
  const r = validatePlan(p);
  assert.strictEqual(r.ok, false);
  if (!r.ok) assert.match(r.error, /tier/);
});

test('reviewer rejects fewer than 2 chapters', () => {
  const p: any = validReviewer();
  p.chapters = [{ title: 'Intro', beats: [] }];
  const r = validatePlan(p);
  if (!r.ok) assert.match(r.error, /chapters/);
  else assert.fail('should have failed');
});

test('reviewer rejects more than 4 chapters', () => {
  const p: any = validReviewer();
  p.chapters = [
    { title: 'Intro', beats: [] },
    { title: 'A', beats: [] },
    { title: 'B', beats: [] },
    { title: 'C', beats: [] },
    { title: 'Result', beats: [] },
  ];
  const r = validatePlan(p);
  if (!r.ok) assert.match(r.error, /chapters/);
  else assert.fail('should have failed');
});

test('requires first chapter to be Intro', () => {
  const p: any = validReviewer();
  p.chapters[0].title = 'Start';
  const r = validatePlan(p);
  if (!r.ok) assert.match(r.error, /Intro/i);
  else assert.fail('should have failed');
});

test('requires last chapter to be Result', () => {
  const p: any = validReviewer();
  p.chapters[p.chapters.length - 1].title = 'End';
  const r = validatePlan(p);
  if (!r.ok) assert.match(r.error, /Result/i);
  else assert.fail('should have failed');
});

test('Intro/Result match is case-insensitive', () => {
  const p: any = validReviewer();
  p.chapters[0].title = 'intro';
  p.chapters[p.chapters.length - 1].title = 'RESULT';
  assert.strictEqual(validatePlan(p).ok, true);
});

test('rejects duplicate chapter titles', () => {
  const p: any = validReviewer();
  p.chapters = [
    { title: 'Intro', beats: [] },
    { title: 'Intro', beats: [] },
    { title: 'Result', beats: [] },
  ];
  const r = validatePlan(p);
  if (!r.ok) assert.match(r.error, /unique/i);
  else assert.fail('should have failed');
});

test('rejects duration out of range for reviewer', () => {
  const p: any = validReviewer();
  p.expected_duration_seconds = 15;
  const r1 = validatePlan(p);
  if (!r1.ok) assert.match(r1.error, /duration/);
  else assert.fail('should have failed');
  p.expected_duration_seconds = 200;
  const r2 = validatePlan(p);
  if (!r2.ok) assert.match(r2.error, /duration/);
  else assert.fail('should have failed');
});

test('rejects emphasize_budget > 3 for reviewer', () => {
  const p: any = validReviewer();
  p.emphasize_budget = 4;
  const r = validatePlan(p);
  if (!r.ok) assert.match(r.error, /emphasize/);
  else assert.fail('should have failed');
});

test('rejects callout_budget > 2 for reviewer', () => {
  const p: any = validReviewer();
  p.callout_budget = 3;
  const r = validatePlan(p);
  if (!r.ok) assert.match(r.error, /callout/);
  else assert.fail('should have failed');
});

test('director tier accepts 4-8 chapters, higher budgets, 60-240s duration', () => {
  const p: any = validReviewer();
  p.tier = 'director';
  p.chapters = [
    { title: 'Intro', beats: [] },
    { title: 'A', beats: [] },
    { title: 'B', beats: [] },
    { title: 'C', beats: [] },
    { title: 'D', beats: [] },
    { title: 'E', beats: [] },
    { title: 'Result', beats: [] },
  ];
  p.expected_duration_seconds = 180;
  p.emphasize_budget = 5;
  p.callout_budget = 4;
  assert.strictEqual(validatePlan(p).ok, true);
});
