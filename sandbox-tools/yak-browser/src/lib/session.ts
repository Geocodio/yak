import { readFileSync, writeFileSync, existsSync, unlinkSync, mkdirSync } from 'node:fs';
import { join } from 'node:path';

export type FastforwardState = { factor: number; startedAtMs: number } | null;

export type Chapter = { title: string; declared: boolean; consumed: boolean };

export type Session = {
  startedAtMs: number;
  storyboardPath: string;
  emphasizeBudget: number;
  calloutBudget: number;
  emphasizePending: boolean;
  openFastforward: FastforwardState;
  chapters: Chapter[];
  expectedDurationSeconds: number | null;
  tier: 'reviewer' | 'director' | null;
};

const SESSION_FILENAME = '.session';

function path(artifactsDir: string): string {
  return join(artifactsDir, SESSION_FILENAME);
}

export function nowMs(): number {
  return Date.now();
}

export function startSession(
  artifactsDir: string,
  init: {
    storyboardPath: string;
    emphasizeBudget?: number;
    calloutBudget?: number;
    chapters?: Chapter[];
    tier?: 'reviewer' | 'director';
    expectedDurationSeconds?: number | null;
  }
): Session {
  mkdirSync(artifactsDir, { recursive: true });
  const session: Session = {
    startedAtMs: nowMs(),
    storyboardPath: init.storyboardPath,
    emphasizeBudget: init.emphasizeBudget ?? 0,
    calloutBudget: init.calloutBudget ?? 0,
    emphasizePending: false,
    openFastforward: null,
    chapters: init.chapters ?? [],
    expectedDurationSeconds: init.expectedDurationSeconds ?? null,
    tier: init.tier ?? null,
  };
  writeFileSync(path(artifactsDir), JSON.stringify(session));
  return session;
}

export function readSession(artifactsDir: string): Session | null {
  if (!existsSync(path(artifactsDir))) return null;
  return JSON.parse(readFileSync(path(artifactsDir), 'utf8')) as Session;
}

export function writeSession(artifactsDir: string, session: Session): void {
  writeFileSync(path(artifactsDir), JSON.stringify(session));
}

export function clearSession(artifactsDir: string): void {
  if (existsSync(path(artifactsDir))) unlinkSync(path(artifactsDir));
}

export function elapsedSeconds(artifactsDir: string): number | null {
  const s = readSession(artifactsDir);
  if (!s) return null;
  return (nowMs() - s.startedAtMs) / 1000;
}
