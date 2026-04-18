import { readFileSync, writeFileSync, existsSync } from 'node:fs';
import { readSession, elapsedSeconds } from './session.ts';

export type Plan = {
  tier: 'reviewer' | 'director';
  goal: string;
  chapters: Array<{ title: string; beats: string[] }>;
  expected_duration_seconds: number;
  emphasize_budget: number;
  callout_budget: number;
  fastforward_segments: Array<unknown>;
};

export type StoryboardEvent =
  | { t: number; type: 'chapter'; title: string }
  | { t: number; type: 'narrate'; text: string }
  | { t: number; type: 'callout'; text: string; selector: string; anchor?: 'top' | 'bottom' | 'left' | 'right' }
  | { t: number; type: 'emphasize' }
  | { t: number; type: 'fastforward'; start: boolean; factor?: number }
  | { t: number; type: 'note'; text: string }
  | { t: number; type: 'click'; x: number; y: number; selector?: string }
  | { t: number; type: 'keypress'; keys: string }
  | { t: number; type: 'navigate'; url: string };

export type Storyboard = { version: 1; plan: Plan; events: StoryboardEvent[] };

export function writeInitialStoryboard(path: string, plan: Plan): void {
  const sb: Storyboard = { version: 1, plan, events: [] };
  writeFileSync(path, JSON.stringify(sb, null, 2));
}

export function readStoryboard(path: string): Storyboard {
  if (!existsSync(path)) throw new Error(`storyboard not found at ${path}`);
  return JSON.parse(readFileSync(path, 'utf8')) as Storyboard;
}

export function appendEvent(artifactsDir: string, event: Omit<StoryboardEvent, 't'>): void {
  const s = readSession(artifactsDir);
  if (!s) throw new Error('no active session — call `record start` first');
  const t = elapsedSeconds(artifactsDir);
  if (t === null) throw new Error('no active session');
  const sb = readStoryboard(s.storyboardPath);
  sb.events.push({ t: Math.round(t * 100) / 100, ...event } as StoryboardEvent);
  writeFileSync(s.storyboardPath, JSON.stringify(sb, null, 2));
}
