import React from 'react';
import { Img, staticFile } from 'remotion';
import { classifySrc } from '../../lib/v3/assets';
import type { Theme } from '../../lib/v3/types';

export type ThemeLogoProps = {
  theme: Theme;
  height: number;
};

/** The installation's logo, or nothing. There is no built-in branding. */
export const ThemeLogo: React.FC<ThemeLogoProps> = ({ theme, height }) => {
  if (!theme.logo) {
    return null;
  }
  const src = classifySrc(theme.logo);
  return (
    <Img
      src={src.kind === 'static' ? staticFile(src.value) : src.value}
      style={{ height, width: 'auto', objectFit: 'contain' }}
    />
  );
};
