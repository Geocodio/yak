import type { Plan } from './storyboard.ts';

export type ValidationResult = { ok: true } | { ok: false; error: string };

const LIMITS = {
  reviewer: { chaptersMin: 2, chaptersMax: 4, durationMin: 20, durationMax: 120, emphasizeMax: 3, calloutMax: 2 },
  director: { chaptersMin: 4, chaptersMax: 8, durationMin: 60, durationMax: 240, emphasizeMax: 6, calloutMax: 6 },
} as const;

export function validatePlan(p: unknown): ValidationResult {
  if (!p || typeof p !== 'object') return { ok: false, error: 'plan must be an object' };
  const plan = p as Partial<Plan>;
  if (plan.tier !== 'reviewer' && plan.tier !== 'director') {
    return { ok: false, error: 'tier must be "reviewer" or "director"' };
  }
  const limits = LIMITS[plan.tier];
  if (!Array.isArray(plan.chapters)) return { ok: false, error: 'chapters must be an array' };
  if (plan.chapters.length < limits.chaptersMin || plan.chapters.length > limits.chaptersMax) {
    return {
      ok: false,
      error: `tier ${plan.tier} requires ${limits.chaptersMin}-${limits.chaptersMax} chapters (got ${plan.chapters.length})`,
    };
  }
  const titles = plan.chapters.map((c) => c.title?.trim() ?? '');
  if (titles[0].toLowerCase() !== 'intro') return { ok: false, error: 'first chapter must be titled "Intro"' };
  if (titles[titles.length - 1].toLowerCase() !== 'result')
    return { ok: false, error: 'last chapter must be titled "Result"' };
  const seen = new Set<string>();
  for (const t of titles) {
    const key = t.toLowerCase();
    if (seen.has(key)) return { ok: false, error: `chapter titles must be unique; duplicate: "${t}"` };
    seen.add(key);
  }
  const d = plan.expected_duration_seconds;
  if (typeof d !== 'number' || d < limits.durationMin || d > limits.durationMax) {
    return {
      ok: false,
      error: `expected_duration_seconds must be ${limits.durationMin}-${limits.durationMax} for ${plan.tier} tier (got ${d})`,
    };
  }
  if (
    typeof plan.emphasize_budget !== 'number' ||
    plan.emphasize_budget < 0 ||
    plan.emphasize_budget > limits.emphasizeMax
  ) {
    return { ok: false, error: `emphasize_budget must be 0-${limits.emphasizeMax} for ${plan.tier} tier` };
  }
  if (typeof plan.callout_budget !== 'number' || plan.callout_budget < 0 || plan.callout_budget > limits.calloutMax) {
    return { ok: false, error: `callout_budget must be 0-${limits.calloutMax} for ${plan.tier} tier` };
  }
  return { ok: true };
}
