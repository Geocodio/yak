import type { PartialTheme, Theme } from './types';

/** Spec §9 defaults: a neutral palette, no Yak colors and no mascot. */
export const DEFAULT_THEME: Theme = {
  colors: {
    background: '#f5f0e8',
    surface: '#3d4f5f',
    ink: '#1f2428',
    muted: '#4e5049',
    accent: '#c4744a',
    done: '#7a8c5e',
    captionBg: 'rgba(31,36,40,0.92)',
  },
  fonts: {
    display: 'Bricolage Grotesque',
    body: 'Instrument Sans',
    mono: 'JetBrains Mono',
  },
  logo: null,
};

export function resolveTheme(theme?: PartialTheme | null): Theme {
  return {
    colors: { ...DEFAULT_THEME.colors, ...(theme?.colors ?? {}) },
    fonts: { ...DEFAULT_THEME.fonts, ...(theme?.fonts ?? {}) },
    logo: theme?.logo ?? null,
  };
}
