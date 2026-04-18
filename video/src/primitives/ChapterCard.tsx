import { AbsoluteFill, interpolate, useCurrentFrame, useVideoConfig } from 'remotion';
import { colors, fonts } from '../lib/styling';

export const ChapterCard = ({ title, durationFrames }: { title: string; durationFrames: number }) => {
  const frame = useCurrentFrame();
  const { fps } = useVideoConfig();
  const fadeIn = interpolate(frame, [0, fps * 0.15], [0, 1], { extrapolateRight: 'clamp' });
  const fadeOut = interpolate(frame, [durationFrames - fps * 0.2, durationFrames], [1, 0], { extrapolateLeft: 'clamp' });
  const opacity = Math.min(fadeIn, fadeOut);
  return (
    <AbsoluteFill style={{ background: colors.chapterOverlayBg, opacity, alignItems: 'center', justifyContent: 'center' }}>
      <div style={{ color: colors.fg, fontFamily: fonts.primary, fontSize: 64, fontWeight: 600, letterSpacing: -0.5 }}>{title}</div>
    </AbsoluteFill>
  );
};
