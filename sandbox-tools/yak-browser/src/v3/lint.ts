import { ACTION_KEYS, PHYSICAL_ACTION_KEYS, type Action, type Script } from './types.ts';
import { estimatedCutSeconds } from './readingTime.ts';

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

const RESERVED_CHAPTERS = ['intro', 'result', 'before'];
const HOSTNAME_PATTERN = /(localhost|127\.0\.0\.1|0\.0\.0\.0|\.local\b|\.test\b|:\d{4,5}\b|preview[-.][\w-]*\.[\w-]+)/i;
const YAK_PATTERN = /\byak\b/i;

function lintChapters(shots: Script['shots'], errors: string[]): void {
  const order: string[] = [];
  for (const shot of shots) {
    const chapter = typeof shot.chapter === 'string' ? shot.chapter.trim() : '';
    if (order[order.length - 1] !== chapter) order.push(chapter);
  }
  const distinct = new Set(order);
  if (distinct.size < 2 || distinct.size > 5) {
    errors.push(`chapters: expected 2-5 distinct chapters, got ${distinct.size}`);
  }
  if (order.length !== distinct.size) {
    errors.push('chapters: must be contiguous — a chapter may not resume after another chapter');
  }
  for (const chapter of distinct) {
    if (RESERVED_CHAPTERS.includes(chapter.toLowerCase())) {
      errors.push(`chapters: "${chapter}" is a reserved chapter name`);
    }
  }
}

function lintRepetition(shots: Script['shots'], errors: string[]): void {
  for (let i = 1; i < shots.length; i += 1) {
    const previous = shots[i - 1];
    const current = shots[i];
    const sameFocus = (previous.focus ?? null) === (current.focus ?? null);
    const sameDo = JSON.stringify(previous.do) === JSON.stringify(current.do);
    if (sameFocus && sameDo) {
      errors.push(`shots[${i}]: repeats the previous shot (same focus and identical do)`);
    }
  }
}

function lintText(label: string, text: unknown, errors: string[]): void {
  if (typeof text !== 'string') return;
  if (HOSTNAME_PATTERN.test(text)) {
    errors.push(`${label}: must not mention hostnames or ports (localhost, preview URLs)`);
  }
  if (YAK_PATTERN.test(text)) {
    errors.push(`${label}: must not mention "Yak"`);
  }
}

function lintScreenshots(script: Script, errors: string[]): void {
  const entries = script.screenshots;
  if (!Array.isArray(entries) || entries.length < 1 || entries.length > 5) {
    errors.push('screenshots must have 1-5 entries');
    return;
  }
  const shotIds = new Set(script.shots.map((s) => s.id));
  const seen = new Set<string>();
  entries.forEach((raw, index) => {
    const ref = `screenshots[${index}]`;
    if (!isRecord(raw)) {
      errors.push(`${ref}: entry must be an object`);
      return;
    }
    const entry = raw as Partial<Script['screenshots'][number]>;
    if (typeof entry.id !== 'string' || !SLUG.test(entry.id)) {
      errors.push(`${ref}: id must be a slug (lowercase letters, digits and single hyphens)`);
    } else if (seen.has(entry.id)) {
      errors.push(`${ref}: duplicate screenshot id "${entry.id}"`);
    } else {
      seen.add(entry.id);
    }
    if (typeof entry.caption !== 'string' || entry.caption.length === 0 || entry.caption.length > 100) {
      errors.push(`${ref}: caption must be a non-empty string of at most 100 characters`);
    } else {
      lintText(`${ref}.caption`, entry.caption, errors);
    }
    if (entry.after_shot === undefined) {
      if (!Array.isArray(entry.do) || entry.do.length === 0) {
        errors.push(`${ref}: needs an after_shot or its own do list`);
      } else {
        lintActions(ref, entry.do, errors);
      }
    } else if (typeof entry.after_shot !== 'string' || !shotIds.has(entry.after_shot)) {
      errors.push(`${ref}: after_shot "${String(entry.after_shot)}" names no shot`);
    }
  });
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

  const complete = script as Script;
  lintChapters(complete.shots, errors);
  lintRepetition(complete.shots, errors);
  lintText('title', complete.title, errors);
  lintText('intro', complete.intro, errors);
  lintText('outro', complete.outro, errors);
  (Array.isArray(complete.summary) ? complete.summary : []).forEach((bullet, i) => lintText(`summary[${i}]`, bullet, errors));
  complete.shots.forEach((shot, i) => lintText(`shots[${i}].say`, shot.say, errors));
  lintScreenshots(complete, errors);

  const shotsWellFormed = complete.shots.every(
    (shot) => isRecord(shot) && typeof shot.say === 'string' && typeof shot.chapter === 'string',
  );
  const cutFieldsWellFormed =
    typeof complete.intro === 'string' &&
    typeof complete.outro === 'string' &&
    Array.isArray(complete.summary);
  if (shotsWellFormed && cutFieldsWellFormed) {
    const seconds = estimatedCutSeconds(complete);
    if (seconds < 30 || seconds > 150) {
      errors.push(`estimated cut length is ${seconds.toFixed(1)} s; it must be between 30 s and 150 s`);
    }
  }

  return errors;
}
