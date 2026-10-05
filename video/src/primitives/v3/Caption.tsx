import React from 'react';
import {
  CAPTION_EDGE_MARGIN,
  CAPTION_FONT_SIZE,
  CAPTION_LINE_HEIGHT,
  CAPTION_MAX_WIDTH,
  CAPTION_PADDING_X,
  CAPTION_PADDING_Y,
  CAPTION_RULE_WIDTH,
} from '../../lib/v3/captions';
import type { CaptionPlacement } from '../../lib/v3/captions';
import type { Theme } from '../../lib/v3/types';
import type { ResolvedFonts } from './useThemeFonts';

export type CaptionProps = {
  text: string;
  theme: Theme;
  fonts: ResolvedFonts;
  opacity: number;
  /** Pixels the pill is offset vertically; animates 16 -> 0 (or -16 -> 0 at the top) on entry. */
  translateY: number;
  /** Lower third by default; `top` keeps the caption clear of a low spotlight. */
  placement?: CaptionPlacement;
  /** Space above the footage (the browser bar) that a top caption must clear. */
  topInset?: number;
};

/** The shot's `say`, in a dark pill with an accent rule, at the bottom or top of the footage. */
export const Caption: React.FC<CaptionProps> = ({
  text,
  theme,
  fonts,
  opacity,
  translateY,
  placement = 'bottom',
  topInset = 0,
}) => (
  <div
    style={{
      position: 'absolute',
      left: 0,
      right: 0,
      ...(placement === 'top' ? { top: topInset + CAPTION_EDGE_MARGIN } : { bottom: CAPTION_EDGE_MARGIN }),
      display: 'flex',
      justifyContent: 'center',
      opacity,
      transform: `translateY(${translateY}px)`,
    }}
  >
    <div
      style={{
        maxWidth: CAPTION_MAX_WIDTH,
        background: theme.colors.captionBg,
        color: theme.colors.background,
        fontFamily: fonts.body,
        fontSize: CAPTION_FONT_SIZE,
        lineHeight: CAPTION_LINE_HEIGHT,
        padding: `${CAPTION_PADDING_Y}px ${CAPTION_PADDING_X}px`,
        borderRadius: 12,
        borderLeft: `${CAPTION_RULE_WIDTH}px solid ${theme.colors.accent}`,
        boxShadow: '0 8px 30px rgba(0,0,0,0.35)',
      }}
    >
      {text}
    </div>
  </div>
);
