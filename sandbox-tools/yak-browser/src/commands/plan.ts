import { readFileSync, existsSync } from 'node:fs';
import { readSession, writeSession } from '../lib/session.ts';
import { writeInitialStoryboard } from '../lib/storyboard.ts';
import { validatePlan } from '../lib/validation.ts';
import type { Plan } from '../lib/storyboard.ts';

export function runPlan(opts: { artifactsDir: string; planPath: string }): number {
  const session = readSession(opts.artifactsDir);
  if (!session) {
    process.stderr.write('yak-browser plan: no active session — call `record start` first\n');
    return 3;
  }
  if (session.chapters.length > 0 || session.emphasizeBudget > 0 || session.calloutBudget > 0) {
    process.stderr.write('yak-browser plan: plan already submitted for this session. Stop recording and start over to replan.\n');
    return 4;
  }
  if (!existsSync(opts.planPath)) {
    process.stderr.write(`yak-browser plan: file not found: ${opts.planPath}\n`);
    return 5;
  }
  let raw: unknown;
  try {
    raw = JSON.parse(readFileSync(opts.planPath, 'utf8'));
  } catch (e) {
    process.stderr.write(`yak-browser plan: invalid JSON: ${(e as Error).message}\n`);
    return 6;
  }
  const result = validatePlan(raw);
  if (!result.ok) {
    process.stderr.write(`yak-browser plan: ${result.error}\n`);
    return 7;
  }
  const plan = raw as Plan;
  writeInitialStoryboard(session.storyboardPath, plan);
  session.chapters = plan.chapters.map((c) => ({ title: c.title, declared: true, consumed: false }));
  session.emphasizeBudget = plan.emphasize_budget;
  session.calloutBudget = plan.callout_budget;
  session.expectedDurationSeconds = plan.expected_duration_seconds;
  session.tier = plan.tier;
  writeSession(opts.artifactsDir, session);
  process.stdout.write(`yak-browser plan: accepted (${plan.chapters.length} chapters, tier ${plan.tier})\n`);
  return 0;
}
