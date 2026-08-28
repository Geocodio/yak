import React from 'react';
import { AbsoluteFill, interpolate, useCurrentFrame } from 'remotion';
import type { ChapterBlock } from '../../lib/v3/blocks';
import type { Theme } from '../../lib/v3/types';
import type { ResolvedFonts } from './useThemeFonts';

export type ChapterCardProps = {
  block: ChapterBlock;
  theme: Theme;
  fonts: ResolvedFonts;
};

/** Chapter marker: counter, title, and the first shot's narration as a lead-in. */
export const ChapterCard: React.FC<ChapterCardProps> = ({ block, theme, fonts }) => {
  const frame = useCurrentFrame();
  const rise = (delay: number) =>
    interpolate(frame, [delay, delay + 10], [0, 1], { extrapolateLeft: 'clamp', extrapolateRight: 'clamp' });

  return (
    <AbsoluteFill
      style={{
        background: theme.colors.surface,
        justifyContent: 'center',
        padding: '0 84px',
        fontFamily: fonts.body,
        color: theme.colors.background,
      }}
    >
      <div style={{ opacity: rise(2) }}>
        <div style={{ fontFamily: fonts.mono, fontSize: 18, letterSpacing: 1.5, opacity: 0.8 }}>
          {block.index} / {block.total}
        </div>
      </div>
      <div style={{ opacity: rise(5), transform: `translateY(${(1 - rise(5)) * 14}px)` }}>
        <div style={{ fontFamily: fonts.display, fontWeight: 700, fontSize: 72, letterSpacing: -1.5, marginTop: 10 }}>
          {block.title}
        </div>
      </div>
      <div style={{ opacity: rise(10) * 0.85, transform: `translateY(${(1 - rise(10)) * 12}px)` }}>
        <div style={{ fontSize: 26, lineHeight: 1.4, marginTop: 20, maxWidth: 980 }}>{block.leadSay}</div>
      </div>
    </AbsoluteFill>
  );
};
