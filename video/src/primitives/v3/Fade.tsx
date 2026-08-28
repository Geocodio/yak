import React from 'react';
import { AbsoluteFill, interpolate, useCurrentFrame } from 'remotion';

export type FadeProps = {
  /** Frames over which this block fades in from the backdrop. */
  inFrames: number;
  /** Frames at the tail over which it fades out, overlapping the next block. */
  outFrames: number;
  /** Total frames this block's sequence occupies, including `outFrames`. */
  totalFrames: number;
  style?: React.CSSProperties;
  children: React.ReactNode;
};

/**
 * Block-level crossfade. Each block owns its lead-in and is rendered in a
 * sequence extended by the next block's lead-in, so the outgoing block fades
 * out underneath the incoming one without either losing readable time.
 */
export const Fade: React.FC<FadeProps> = ({ inFrames, outFrames, totalFrames, style, children }) => {
  const frame = useCurrentFrame();
  // interpolate() requires strictly increasing stops, so clamp them into order
  // rather than trusting the caller's frame counts.
  const inStop = Math.max(inFrames, 1);
  const outStop = Math.max(totalFrames - outFrames, inStop + 1);
  const endStop = Math.max(totalFrames, outStop + 1);
  const opacity = interpolate(
    frame,
    [0, inStop, outStop, endStop],
    [inFrames > 0 ? 0 : 1, 1, 1, outFrames > 0 ? 0 : 1],
    { extrapolateLeft: 'clamp', extrapolateRight: 'clamp' },
  );

  return <AbsoluteFill style={{ opacity, ...style }}>{children}</AbsoluteFill>;
};
