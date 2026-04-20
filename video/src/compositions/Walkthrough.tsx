import { AbsoluteFill, Sequence, Video, useVideoConfig, staticFile } from 'remotion';
import type { Storyboard, StoryboardEvent } from '../lib/storyboard';
import { buildTimeline, compositionDuration, storyToComp, Segment } from '../lib/timeline';
import { ChapterBanner } from '../primitives/ChapterBanner';
import { ClickRipple } from '../primitives/ClickRipple';
import { KeypressBadge } from '../primitives/KeypressBadge';
import { UrlPill, NavigateStop } from '../primitives/UrlPill';
import { Callout } from '../primitives/Callout';
import { CaptionStrip } from '../primitives/CaptionStrip';
import { FastForwardBadge } from '../primitives/FastForwardBadge';
import { MusicBed } from '../primitives/MusicBed';
import { ZoomLayer, ZoomSpec } from '../primitives/SmartZoom';

export type WalkthroughProps = {
  videoUrl: string;
  storyboard: Storyboard;
  videoDurationSeconds: number | null;
  musicTrack: string | null;
  tier: 'reviewer' | 'director';
};

const FAST_FACTOR = 4;
const FAST_MIN_GAP_SECONDS = 2.5;
const FAST_LEAD_SECONDS = 0.4;

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

export function walkthroughCompositionDuration(
  storyboard: Storyboard,
  videoDurationSeconds: number | null,
): number {
  const duration = fallbackDuration(storyboard, videoDurationSeconds);
  const segments = buildTimeline(storyboard.events, duration, {
    minGapSeconds: FAST_MIN_GAP_SECONDS,
    factor: FAST_FACTOR,
    leadSeconds: FAST_LEAD_SECONDS,
  });
  return compositionDuration(segments);
}

function fallbackDuration(storyboard: Storyboard, videoDurationSeconds: number | null): number {
  if (videoDurationSeconds && videoDurationSeconds > 0) return videoDurationSeconds;
  const last = storyboard.events[storyboard.events.length - 1];
  return last ? Math.max(last.t + 3, 15) : 15;
}

export const Walkthrough = ({
  videoUrl,
  storyboard,
  videoDurationSeconds,
  musicTrack,
}: WalkthroughProps) => {
  const { fps, width } = useVideoConfig();
  const events = storyboard.events;
  const resolvedVideoUrl = resolveVideoUrl(videoUrl);
  const duration = fallbackDuration(storyboard, videoDurationSeconds);

  const segments = buildTimeline(events, duration, {
    minGapSeconds: FAST_MIN_GAP_SECONDS,
    factor: FAST_FACTOR,
    leadSeconds: FAST_LEAD_SECONDS,
  });

  const tComp = (t: number) => storyToComp(t, segments);
  const tFrame = (t: number) => Math.round(tComp(t) * fps);

  const zoomLifetimeFrames = Math.round(fps * 2.0);
  const zooms: ZoomSpec[] = [];
  for (let i = 0; i < events.length; i++) {
    const ev = events[i];
    if (ev.type !== 'emphasize') continue;
    for (let j = i + 1; j < events.length; j++) {
      const next = events[j];
      if (next.type === 'click') {
        if (next.x === 0 && next.y === 0) break;
        zooms.push({
          fromFrame: tFrame(next.t) - Math.round(fps * 0.1),
          durationFrames: zoomLifetimeFrames,
          x: next.x,
          y: next.y,
        });
        break;
      }
      if (next.type === 'keypress') {
        zooms.push({
          fromFrame: tFrame(next.t) - Math.round(fps * 0.1),
          durationFrames: zoomLifetimeFrames,
          x: width / 2,
          y: 80,
        });
        break;
      }
    }
  }

  const navigates: NavigateStop[] = events
    .filter((e): e is Extract<StoryboardEvent, { type: 'navigate' }> => e.type === 'navigate')
    .map((e) => ({ t: tComp(e.t), url: e.url }));

  return (
    <AbsoluteFill style={{ background: '#000' }}>
      <ZoomLayer zooms={zooms}>
        <>
          {segments.map((seg, i) => (
            <VideoSegment key={`v-${i}`} segment={seg} videoUrl={resolvedVideoUrl} />
          ))}
        </>
      </ZoomLayer>
      {segments
        .filter((s): s is Extract<Segment, { kind: 'fast' }> => s.kind === 'fast')
        .map((seg, i) => (
          <Sequence
            key={`ff-${i}`}
            from={Math.round(seg.compStart * fps)}
            durationInFrames={Math.max(1, Math.round(seg.compDuration * fps))}
          >
            <FastForwardBadge factor={seg.factor} />
          </Sequence>
        ))}
      <UrlPill navigates={navigates} />
      {events.map((ev, i) => {
        const frame = tFrame(ev.t);
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
          case 'callout':
            return (
              <Sequence key={i} from={frame} durationInFrames={duration}>
                <Callout text={ev.text} rect={ev.rect} anchor={ev.anchor} />
              </Sequence>
            );
          default:
            return null;
        }
      })}
      <MusicBed src={musicTrack} />
    </AbsoluteFill>
  );
};

const VideoSegment = ({ segment, videoUrl }: { segment: Segment; videoUrl: string }) => {
  const { fps } = useVideoConfig();
  const from = Math.round(segment.compStart * fps);
  const durationInFrames = Math.max(1, Math.round(segment.compDuration * fps));
  const startFrom = Math.round(segment.storyStart * fps);
  const playbackRate = segment.kind === 'fast' ? segment.factor : 1;
  return (
    <Sequence from={from} durationInFrames={durationInFrames}>
      <Video src={videoUrl} startFrom={startFrom} playbackRate={playbackRate} muted />
    </Sequence>
  );
};
