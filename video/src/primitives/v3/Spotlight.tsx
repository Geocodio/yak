import React from 'react';
import type { Rect } from '../../lib/v3/types';

export type SpotlightProps = {
  rect: Rect;
  /** Vertical offset of the footage inside the composition (the browser bar). */
  offsetY: number;
  accent: string;
  /** 0 = invisible, 1 = full 60 % dim and a solid accent outline. */
  progress: number;
};

const PADDING = 14;
const DIM = 0.6;

/** Dim everything outside the focus rect and outline it in the theme accent. */
export const Spotlight: React.FC<SpotlightProps> = ({ rect, offsetY, accent, progress }) => (
  <div
    style={{
      position: 'absolute',
      left: rect.x - PADDING,
      top: rect.y - PADDING + offsetY,
      width: rect.w + PADDING * 2,
      height: rect.h + PADDING * 2,
      borderRadius: 10,
      boxShadow: `0 0 0 9999px rgba(20,24,28,${DIM * progress})`,
      outline: `3px solid ${accent}`,
      opacity: progress,
      pointerEvents: 'none',
    }}
  />
);
