import React from 'react';
import { AbsoluteFill, interpolate, useCurrentFrame } from 'remotion';
import type { Script, Theme } from '../../lib/v3/types';
import { ThemeLogo } from './ThemeLogo';
import type { ResolvedFonts } from './useThemeFonts';

export type TitleCardProps = {
  script: Script;
  theme: Theme;
  fonts: ResolvedFonts;
};

const Rise: React.FC<{ delay: number; children: React.ReactNode; style?: React.CSSProperties }> = ({
  delay,
  children,
  style,
}) => {
  const frame = useCurrentFrame();
  const progress = interpolate(frame, [delay, delay + 12], [0, 1], {
    extrapolateLeft: 'clamp',
    extrapolateRight: 'clamp',
  });
  return (
    <div style={{ opacity: progress, transform: `translateY(${(1 - progress) * 18}px)`, ...style }}>{children}</div>
  );
};

function eyebrow(script: Script): string | null {
  const repo = script.task?.repo ?? null;
  if (!repo) {
    return null;
  }
  const pr = script.task?.pr;
  return typeof pr === 'number' ? `${repo} · PR #${pr}` : repo;
}

/** Opening card: repo eyebrow, title in the display face, intro in the body face. */
export const TitleCard: React.FC<TitleCardProps> = ({ script, theme, fonts }) => {
  const label = eyebrow(script);
  return (
    <AbsoluteFill
      style={{
        background: theme.colors.background,
        padding: '72px 84px',
        fontFamily: fonts.body,
        color: theme.colors.ink,
      }}
    >
      {theme.logo ? (
        <div style={{ position: 'absolute', top: 56, left: 84 }}>
          <ThemeLogo theme={theme} height={40} />
        </div>
      ) : null}
      <div style={{ flex: 1 }} />
      {label ? (
        <Rise delay={4}>
          <div
            style={{
              fontFamily: fonts.mono,
              fontSize: 18,
              color: theme.colors.muted,
              letterSpacing: 1.5,
              textTransform: 'uppercase',
            }}
          >
            {label}
          </div>
        </Rise>
      ) : null}
      <Rise delay={8}>
        <div
          style={{
            fontFamily: fonts.display,
            fontWeight: 700,
            fontSize: 64,
            lineHeight: 1.06,
            letterSpacing: -1.5,
            maxWidth: 1100,
            marginTop: 16,
          }}
        >
          {script.title}
        </div>
      </Rise>
      <Rise delay={14}>
        <div style={{ fontSize: 26, lineHeight: 1.4, color: theme.colors.surface, maxWidth: 980, marginTop: 26 }}>
          {script.intro}
        </div>
      </Rise>
      <div style={{ position: 'absolute', right: 0, top: 0, bottom: 0, width: 14, background: theme.colors.accent }} />
    </AbsoluteFill>
  );
};
