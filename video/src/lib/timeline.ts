import type { StoryboardEvent } from './storyboard';

export type Segment =
  | { kind: 'normal'; storyStart: number; storyEnd: number; compStart: number; compDuration: number }
  | {
      kind: 'fast';
      storyStart: number;
      storyEnd: number;
      compStart: number;
      compDuration: number;
      factor: number;
    };

export type TimelineOptions = {
  minGapSeconds: number;
  factor: number;
  leadSeconds: number;
};

function eventInfluenceEnd(ev: StoryboardEvent): number {
  switch (ev.type) {
    case 'narrate':
      return ev.t + 3.0;
    case 'chapter':
      return ev.t + 2.5;
    case 'callout':
      return ev.t + 2.0;
    case 'keypress':
      return ev.t + 1.0;
    case 'click':
      return ev.t + 0.4;
    default:
      return ev.t;
  }
}

export function buildTimeline(
  events: StoryboardEvent[],
  videoDuration: number,
  opts: TimelineOptions,
): Segment[] {
  const { minGapSeconds, factor, leadSeconds } = opts;
  const clamp = (t: number) => Math.max(0, Math.min(videoDuration, t));
  const anchors = new Set<number>();
  anchors.add(0);
  anchors.add(videoDuration);
  for (const ev of events) {
    anchors.add(clamp(ev.t));
    anchors.add(clamp(eventInfluenceEnd(ev)));
  }
  const sorted = Array.from(anchors).sort((a, b) => a - b);

  const segments: Segment[] = [];
  let compCursor = 0;
  const minFastBudget = minGapSeconds + 2 * leadSeconds;
  for (let i = 0; i < sorted.length - 1; i++) {
    const storyStart = sorted[i];
    const storyEnd = sorted[i + 1];
    const storyDur = storyEnd - storyStart;
    if (storyDur <= 0) continue;

    if (storyDur > minFastBudget) {
      segments.push({
        kind: 'normal',
        storyStart,
        storyEnd: storyStart + leadSeconds,
        compStart: compCursor,
        compDuration: leadSeconds,
      });
      compCursor += leadSeconds;

      const fastStart = storyStart + leadSeconds;
      const fastEnd = storyEnd - leadSeconds;
      const fastStoryDur = fastEnd - fastStart;
      const fastCompDur = fastStoryDur / factor;
      segments.push({
        kind: 'fast',
        storyStart: fastStart,
        storyEnd: fastEnd,
        compStart: compCursor,
        compDuration: fastCompDur,
        factor,
      });
      compCursor += fastCompDur;

      segments.push({
        kind: 'normal',
        storyStart: fastEnd,
        storyEnd,
        compStart: compCursor,
        compDuration: leadSeconds,
      });
      compCursor += leadSeconds;
    } else {
      segments.push({
        kind: 'normal',
        storyStart,
        storyEnd,
        compStart: compCursor,
        compDuration: storyDur,
      });
      compCursor += storyDur;
    }
  }
  return segments;
}

export function storyToComp(t: number, segments: Segment[]): number {
  if (segments.length === 0) return t;
  if (t <= segments[0].storyStart) return 0;
  const last = segments[segments.length - 1];
  if (t >= last.storyEnd) return last.compStart + last.compDuration;
  for (const seg of segments) {
    if (t >= seg.storyStart && t <= seg.storyEnd) {
      const local = t - seg.storyStart;
      if (seg.kind === 'fast') return seg.compStart + local / seg.factor;
      return seg.compStart + local;
    }
  }
  return last.compStart + last.compDuration;
}

export function compositionDuration(segments: Segment[]): number {
  if (segments.length === 0) return 0;
  const last = segments[segments.length - 1];
  return last.compStart + last.compDuration;
}
