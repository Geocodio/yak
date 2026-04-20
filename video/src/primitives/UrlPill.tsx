import { interpolate, useCurrentFrame, useVideoConfig } from 'remotion';
import { colors, fonts } from '../lib/styling';

export type NavigateStop = { t: number; url: string };

type Shown = { startSeconds: number; url: string };

const VISIBLE_SECONDS = 3.0;
const FADE_SECONDS = 0.25;

function showings(navigates: NavigateStop[]): Shown[] {
  if (navigates.length === 0) return [];
  const sorted = [...navigates].sort((a, b) => a.t - b.t);
  const out: Shown[] = [{ startSeconds: 0, url: sorted[0].url }];
  for (const n of sorted) {
    const prev = out[out.length - 1];
    if (n.url === prev.url) continue;
    out.push({ startSeconds: n.t, url: n.url });
  }
  return out;
}

function activeShowing(shown: Shown[], seconds: number): { url: string; localSeconds: number } | null {
  for (let i = shown.length - 1; i >= 0; i--) {
    const s = shown[i];
    const local = seconds - s.startSeconds;
    if (local >= 0 && local <= VISIBLE_SECONDS) return { url: s.url, localSeconds: local };
  }
  return null;
}

export const UrlPill = ({ navigates }: { navigates: NavigateStop[] }) => {
  const frame = useCurrentFrame();
  const { fps, width } = useVideoConfig();
  const shown = showings(navigates);
  const active = activeShowing(shown, frame / fps);
  if (active === null) return null;

  const fadeIn = interpolate(active.localSeconds, [0, FADE_SECONDS], [0, 1], {
    extrapolateRight: 'clamp',
  });
  const fadeOut = interpolate(
    active.localSeconds,
    [VISIBLE_SECONDS - FADE_SECONDS, VISIBLE_SECONDS],
    [1, 0],
    { extrapolateLeft: 'clamp' },
  );
  const opacity = Math.min(fadeIn, fadeOut);

  const pillWidth = Math.min(active.url.length * 13 + 32, width - 60);
  return (
    <div
      style={{
        position: 'absolute',
        right: 28,
        top: 28,
        maxWidth: pillWidth,
        padding: '8px 16px',
        background: colors.captionBg,
        color: colors.fg,
        fontFamily: fonts.mono,
        fontSize: 20,
        borderRadius: 999,
        opacity,
        whiteSpace: 'nowrap',
        overflow: 'hidden',
        textOverflow: 'ellipsis',
      }}
    >
      {active.url}
    </div>
  );
};
