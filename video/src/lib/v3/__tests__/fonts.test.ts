import { describe, expect, it } from 'vitest';
import { FONT_FALLBACKS, supportedFontFamilies, themeFontFamily } from '../fonts';
import { DEFAULT_THEME } from '../theme';

describe('supportedFontFamilies', () => {
  it('includes the three default theme families', () => {
    const families = supportedFontFamilies();
    expect(families).toContain(DEFAULT_THEME.fonts.display);
    expect(families).toContain(DEFAULT_THEME.fonts.body);
    expect(families).toContain(DEFAULT_THEME.fonts.mono);
  });

  it('is sorted and free of duplicates', () => {
    const families = supportedFontFamilies();
    expect(families).toEqual([...families].sort());
    expect(new Set(families).size).toBe(families.length);
  });
});

describe('themeFontFamily', () => {
  it('falls back for an unknown family instead of throwing', () => {
    expect(themeFontFamily('Definitely Not A Font', 'body')).toBe(FONT_FALLBACKS.body);
    expect(themeFontFamily('Definitely Not A Font', 'display')).toBe(FONT_FALLBACKS.display);
    expect(themeFontFamily('Definitely Not A Font', 'mono')).toBe(FONT_FALLBACKS.mono);
  });

  it('falls back for null, undefined and empty values', () => {
    expect(themeFontFamily(null, 'body')).toBe(FONT_FALLBACKS.body);
    expect(themeFontFamily(undefined, 'body')).toBe(FONT_FALLBACKS.body);
    expect(themeFontFamily('', 'body')).toBe(FONT_FALLBACKS.body);
  });

  it('always ends in the role fallback stack so glyph coverage survives', () => {
    for (const role of ['display', 'body', 'mono'] as const) {
      expect(themeFontFamily(DEFAULT_THEME.fonts[role], role).endsWith(FONT_FALLBACKS[role])).toBe(true);
    }
  });

  it('never throws for any supported family', () => {
    for (const family of supportedFontFamilies()) {
      expect(() => themeFontFamily(family, 'body')).not.toThrow();
      expect(typeof themeFontFamily(family, 'body')).toBe('string');
    }
  });
});
