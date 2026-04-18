import { AbsoluteFill, Sequence, Video, useVideoConfig, staticFile } from 'remotion';
import type { Storyboard, StoryboardEvent } from '../lib/storyboard';
import { ChapterBanner } from '../primitives/ChapterBanner';
import { ClickRipple } from '../primitives/ClickRipple';
import { KeypressBadge } from '../primitives/KeypressBadge';
import { UrlPill } from '../primitives/UrlPill';
import { Callout } from '../primitives/Callout';
import { CaptionStrip } from '../primitives/CaptionStrip';
import { FastForwardBadge } from '../primitives/FastForwardBadge';
import { MusicBed } from '../primitives/MusicBed';

export type WalkthroughProps = {
  videoUrl: string;
  storyboard: Storyboard;
  musicTrack: string | null;
  tier: 'reviewer' | 'director';
};

function tToFrame(t: number, fps: number) {
  return Math.round(t * fps);
}

function overlayDuration(event: StoryboardEvent, fps: number) {
  switch (event.type) {
    case 'chapter':
      return Math.round(fps * 2.5);
    case 'narrate':
      return Math.round(fps * 3.0);
    case 'click':
      return Math.round(fps * 0.4);
    case 'keypress':
      return Math.round(fps * 1.0);
    case 'navigate':
      return Math.round(fps * 1.5);
    case 'callout':
      return Math.round(fps * 2.0);
    default:
      return Math.round(fps * 1.0);
  }
}

function resolveVideoUrl(url: string): string {
  if (url.startsWith('http://') || url.startsWith('https://') || url.startsWith('file://') || url.startsWith('/')) {
    return url;
  }
  return staticFile(url);
}

export const Walkthrough = ({ videoUrl, storyboard, musicTrack }: WalkthroughProps) => {
  const { fps, width, height } = useVideoConfig();
  const events = storyboard.events;
  const resolvedVideoUrl = resolveVideoUrl(videoUrl);

  const ffSegments: Array<{ startFrame: number; endFrame: number; factor: number }> = [];
  let open: { start: number; factor: number } | null = null;
  for (const ev of events) {
    if (ev.type === 'fastforward') {
      if (ev.start) {
        open = { start: ev.t, factor: ev.factor ?? 4 };
      } else if (open) {
        ffSegments.push({
          startFrame: tToFrame(open.start, fps),
          endFrame: tToFrame(ev.t, fps),
          factor: open.factor,
        });
        open = null;
      }
    }
  }

  return (
    <AbsoluteFill style={{ background: '#000' }}>
      <Video src={resolvedVideoUrl} />
      {ffSegments.map((seg, i) => (
        <Sequence key={`ff-${i}`} from={seg.startFrame} durationInFrames={seg.endFrame - seg.startFrame}>
          <FastForwardBadge factor={seg.factor} />
        </Sequence>
      ))}
      {events.map((ev, i) => {
        const frame = tToFrame(ev.t, fps);
        const duration = overlayDuration(ev, fps);
        switch (ev.type) {
          case 'chapter':
            return (
              <Sequence key={i} from={frame} durationInFrames={duration}>
                <ChapterBanner title={ev.title} durationFrames={duration} />
              </Sequence>
            );
          case 'narrate':
            return (
              <Sequence key={i} from={frame} durationInFrames={duration}>
                <CaptionStrip text={ev.text} durationFrames={duration} />
              </Sequence>
            );
          case 'click':
            return (
              <Sequence key={i} from={frame} durationInFrames={duration}>
                <ClickRipple x={ev.x} y={ev.y} />
              </Sequence>
            );
          case 'keypress':
            return (
              <Sequence key={i} from={frame} durationInFrames={duration}>
                <KeypressBadge keys={ev.keys} />
              </Sequence>
            );
          case 'navigate':
            return (
              <Sequence key={i} from={frame} durationInFrames={duration}>
                <UrlPill url={ev.url} />
              </Sequence>
            );
          case 'callout': {
            const near = [...events]
              .reverse()
              .find((e) => e.t <= ev.t && e.type === 'click') as
              | Extract<StoryboardEvent, { type: 'click' }>
              | undefined;
            const targetX = near?.x ?? width / 2;
            const targetY = near?.y ?? height / 2;
            return (
              <Sequence key={i} from={frame} durationInFrames={duration}>
                <Callout
                  text={ev.text}
                  targetX={targetX}
                  targetY={targetY}
                  rect={ev.rect}
                  anchor={ev.anchor}
                />
              </Sequence>
            );
          }
          default:
            return null;
        }
      })}
      <MusicBed src={musicTrack} />
    </AbsoluteFill>
  );
};
