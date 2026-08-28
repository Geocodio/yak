import React from 'react';
import { AbsoluteFill, interpolate, useCurrentFrame } from 'remotion';
import type { Script, Theme } from '../../lib/v3/types';
import { ThemeLogo } from './ThemeLogo';
import type { ResolvedFonts } from './useThemeFonts';

export type SummaryCardProps = {
  script: Script;
  theme: Theme;
  fonts: ResolvedFonts;
  /** Frames between one bullet checking off and the next. */
  bulletStaggerFrames: number;
};

function pullRequestUrl(script: Script): string | null {
  const repo = script.task?.repo ?? null;
  const pr = script.task?.pr ?? null;
  if (!repo || typeof pr !== 'number') {
    return null;
  }
  return `github.com/${repo}/pull/${pr}`;
}

/** Closing card: "What changed", bullets checking off in turn, and the PR url. */
export const SummaryCard: React.FC<SummaryCardProps> = ({ script, theme, fonts, bulletStaggerFrames }) => {
  const frame = useCurrentFrame();
  const url = pullRequestUrl(script);

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
        <div style={{ position: 'absolute', top: 56, right: 56 }}>
          <ThemeLogo theme={theme} height={40} />
        </div>
      ) : null}
      <div style={{ fontFamily: fonts.display, fontWeight: 700, fontSize: 54, letterSpacing: -1.2 }}>What changed</div>
      <div style={{ marginTop: 28, display: 'flex', flexDirection: 'column', gap: 18 }}>
        {script.summary.map((bullet, index) => {
          const at = 8 + index * bulletStaggerFrames;
          const appear = interpolate(frame, [at, at + 8], [0, 1], {
            extrapolateLeft: 'clamp',
            extrapolateRight: 'clamp',
          });
          const checked = frame >= at + 8;
          return (
            <div
              key={bullet}
              style={{
                display: 'flex',
                alignItems: 'center',
                gap: 18,
                fontSize: 32,
                opacity: appear,
                transform: `translateY(${(1 - appear) * 14}px)`,
              }}
            >
              <div
                style={{
                  width: 36,
                  height: 36,
                  borderRadius: 18,
                  border: `3px solid ${theme.colors.done}`,
                  background: checked ? theme.colors.done : 'transparent',
                  display: 'flex',
                  alignItems: 'center',
                  justifyContent: 'center',
                  color: theme.colors.background,
                  fontSize: 22,
                  fontWeight: 700,
                  flex: '0 0 auto',
                }}
              >
                {checked ? '✓' : ''}
              </div>
              <span>{bullet}</span>
            </div>
          );
        })}
      </div>
      <div style={{ flex: 1 }} />
      {url ? <div style={{ fontFamily: fonts.mono, fontSize: 22, color: theme.colors.muted }}>{url}</div> : null}
      <div style={{ position: 'absolute', right: 0, top: 0, bottom: 0, width: 14, background: theme.colors.done }} />
    </AbsoluteFill>
  );
};
