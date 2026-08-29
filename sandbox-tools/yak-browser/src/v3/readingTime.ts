import type { Script } from './types.ts';

const WORDS_PER_MINUTE = 165;
const READING_PAD_SECONDS = 0.9;
const READING_FLOOR_SECONDS = 2.4;

/** Spec §7: words / 165 wpm x 60 s + 0.9 s, with a 2.4 s floor. */
export function readingTimeSeconds(text: string): number {
  const words = text.trim().split(/\s+/).filter((w) => w.length > 0).length;
  return Math.max(READING_FLOOR_SECONDS, (words / WORDS_PER_MINUTE) * 60 + READING_PAD_SECONDS);
}

function clamp(value: number, min: number, max: number): number {
  return Math.min(max, Math.max(min, value));
}

/**
 * Spec §7 block timeline, without voiceover and without clip lengths (the
 * linter runs before anything is recorded). Title card + one chapter card per
 * distinct chapter + one block per shot + summary card + fades.
 */
export function estimatedCutSeconds(script: Script): number {
  const title = clamp(readingTimeSeconds(script.intro), 4, 8);
  const chapters = new Set(script.shots.map((s) => s.chapter)).size * 2.0;
  const shots = script.shots.reduce((total, shot) => total + clamp(readingTimeSeconds(shot.say), 3, 20), 0);
  const summaryFloor = 5.0 + 0.4 * script.summary.length;
  const summary = clamp(readingTimeSeconds(script.outro) + 1.0, summaryFloor, Math.max(10, summaryFloor));
  const fades = 0.25 + 0.3 + Math.max(0, script.shots.length - 1) * 0.25;
  return title + chapters + shots + summary + fades;
}
