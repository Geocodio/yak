import { ACTION_KEYS, PHYSICAL_ACTION_KEYS, type Action, type Script } from './types.ts';

export const SLUG = /^[a-z0-9]+(?:-[a-z0-9]+)*$/;

function words(text: string): number {
  return text.trim().split(/\s+/).filter((w) => w.length > 0).length;
}

export function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value);
}

export function lintActions(shotRef: string, actions: unknown, errors: string[]): void {
  if (!Array.isArray(actions) || actions.length < 1 || actions.length > 6) {
    errors.push(`${shotRef}: do must have 1-6 actions`);
    return;
  }
  let physical = 0;
  actions.forEach((raw, i) => {
    const ref = `${shotRef}.do[${i}]`;
    if (!isRecord(raw)) {
      errors.push(`${ref}: action must be an object`);
      return;
    }
    for (const key of Object.keys(raw)) {
      if (!(ACTION_KEYS as readonly string[]).includes(key)) {
        errors.push(`${ref}: unknown action "${key}"`);
      }
    }
    const action = raw as Action;
    for (const key of PHYSICAL_ACTION_KEYS) {
      if (action[key] !== undefined) physical += 1;
    }
    if (action.fill !== undefined && typeof action.value !== 'string') {
      errors.push(`${ref}: fill requires a string value`);
    }
    if (typeof action.wait === 'number' && action.wait > 5000) {
      errors.push(`${ref}: wait is capped at 5000 ms`);
    }
  });
  if (physical === 0) {
    errors.push(`${shotRef}: needs at least one physical action (a shot of only waits is rejected)`);
  }
}

/**
 * Every lint rule from spec §4 and §8b that can be checked without a browser.
 * Returns one line per violation; an empty array means the script is clean.
 */
export function lintScriptStatic(input: unknown): string[] {
  const errors: string[] = [];
  if (!isRecord(input)) return ['script must be a JSON object'];
  const script = input as Partial<Script>;

  if (script.version !== 3) errors.push('version must be 3');
  if (typeof script.title !== 'string' || script.title.length === 0 || script.title.length > 90) {
    errors.push('title must be a non-empty string of at most 90 characters');
  }
  if (typeof script.intro !== 'string' || script.intro.length === 0 || script.intro.length > 240) {
    errors.push('intro must be a non-empty string of at most 240 characters');
  }
  if (typeof script.outro !== 'string' || script.outro.length === 0 || script.outro.length > 160) {
    errors.push('outro must be a non-empty string of at most 160 characters');
  }
  if (!Array.isArray(script.summary) || script.summary.length < 2 || script.summary.length > 5) {
    errors.push('summary must have 2-5 bullets');
  } else {
    script.summary.forEach((bullet, i) => {
      if (typeof bullet !== 'string' || bullet.length === 0 || bullet.length > 60) {
        errors.push(`summary[${i}] must be a non-empty string of at most 60 characters`);
      }
    });
  }

  if (!Array.isArray(script.shots) || script.shots.length < 3 || script.shots.length > 12) {
    errors.push('shots must have 3-12 entries');
    return errors;
  }

  const seenIds = new Set<string>();
  script.shots.forEach((raw, index) => {
    const ref = `shots[${index}]`;
    if (!isRecord(raw)) {
      errors.push(`${ref}: shot must be an object`);
      return;
    }
    const shot = raw as Partial<Script['shots'][number]>;
    if (typeof shot.id !== 'string' || !SLUG.test(shot.id)) {
      errors.push(`${ref}: id must be a slug (lowercase letters, digits and single hyphens)`);
    } else if (seenIds.has(shot.id)) {
      errors.push(`${ref}: duplicate shot id "${shot.id}"`);
    } else {
      seenIds.add(shot.id);
    }
    if (typeof shot.chapter !== 'string' || shot.chapter.trim().length === 0) {
      errors.push(`${ref}: chapter is required`);
    }
    if (typeof shot.say !== 'string' || shot.say.length === 0) {
      errors.push(`${ref}: say is required`);
    } else {
      if (shot.say.length > 180) errors.push(`${ref}: say must be at most 180 characters`);
      if (words(shot.say) > 32) errors.push(`${ref}: say must be at most 32 words`);
    }
    if (shot.focus !== undefined && (typeof shot.focus !== 'string' || shot.focus.length === 0)) {
      errors.push(`${ref}: focus must be a non-empty selector when present`);
    }
    lintActions(ref, shot.do, errors);
  });

  return errors;
}
