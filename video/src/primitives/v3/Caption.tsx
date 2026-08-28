import React from 'react';
import {
  CAPTION_FONT_SIZE,
  CAPTION_MAX_WIDTH,
  CAPTION_PADDING_X,
  CAPTION_RULE_WIDTH,
} from '../../lib/v3/captions';
import type { Theme } from '../../lib/v3/types';
import type { ResolvedFonts } from './useThemeFonts';

export type CaptionProps = {
  text: string;
  theme: Theme;
  fonts: ResolvedFonts;
  opacity: number;
  /** Pixels the pill is offset downwards; animates 16 -> 0 on entry. */
  translateY: number;
};

/** Lower-third caption: the shot's `say`, in a dark pill with an accent rule. */
export const Caption: React.FC<CaptionProps> = ({ text, theme, fonts, opacity, translateY }) => (
  <div
    style={{
      position: 'absolute',
      left: 0,
      right: 0,
      bottom: 56,
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
        lineHeight: 1.35,
        padding: `18px ${CAPTION_PADDING_X}px`,
        borderRadius: 12,
        borderLeft: `${CAPTION_RULE_WIDTH}px solid ${theme.colors.accent}`,
        boxShadow: '0 8px 30px rgba(0,0,0,0.35)',
      }}
    >
      {text}
    </div>
  </div>
);
