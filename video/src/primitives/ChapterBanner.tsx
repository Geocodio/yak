import { interpolate, useCurrentFrame, useVideoConfig, Easing } from 'remotion';
import { colors, fonts } from '../lib/styling';

const BANNER_WIDTH = 340;
const BANNER_HEIGHT = 60;

export const ChapterBanner = ({ title, durationFrames }: { title: string; durationFrames: number }) => {
  const frame = useCurrentFrame();
  const { fps } = useVideoConfig();
  const slideInFrames = fps * 0.25;
  const slideOutStart = durationFrames - fps * 0.25;
  const x = interpolate(
    frame,
    [0, slideInFrames, slideOutStart, durationFrames],
    [-BANNER_WIDTH - 20, 24, 24, -BANNER_WIDTH - 20],
    { easing: Easing.inOut(Easing.ease), extrapolateRight: 'clamp' }
  );
  const opacity = interpolate(
    frame,
    [0, slideInFrames * 0.6, slideOutStart, durationFrames],
    [0, 1, 1, 0],
    { extrapolateLeft: 'clamp', extrapolateRight: 'clamp' }
  );
  return (
    <div
      style={{
        position: 'absolute',
        left: x,
        top: 24,
        width: BANNER_WIDTH,
        height: BANNER_HEIGHT,
        background: colors.captionBg,
        color: colors.fg,
        fontFamily: fonts.primary,
        fontSize: 22,
        fontWeight: 600,
        display: 'flex',
        alignItems: 'center',
        padding: '0 22px',
        borderRadius: 12,
        borderLeft: `4px solid ${colors.accent}`,
        boxShadow: '0 6px 24px rgba(0,0,0,0.3)',
        opacity,
      }}
    >
      <span style={{ color: colors.accent, fontSize: 16, marginRight: 12, fontFamily: fonts.mono }}>▸</span>
      {title}
    </div>
  );
};
