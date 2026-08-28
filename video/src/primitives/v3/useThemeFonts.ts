import { useMemo } from 'react';
import { themeFontFamily } from '../../lib/v3/fonts';
import type { Theme } from '../../lib/v3/types';

export type ResolvedFonts = {
  display: string;
  body: string;
  mono: string;
};

/** Resolve the theme's three families to CSS font-family stacks, once per render. */
export function useThemeFonts(theme: Theme): ResolvedFonts {
  return useMemo(
    () => ({
      display: themeFontFamily(theme.fonts.display, 'display'),
      body: themeFontFamily(theme.fonts.body, 'body'),
      mono: themeFontFamily(theme.fonts.mono, 'mono'),
    }),
    [theme.fonts.display, theme.fonts.body, theme.fonts.mono],
  );
}
