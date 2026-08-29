import { describe, expect, it } from 'vitest';
import { DEFAULT_THEME, resolveTheme } from '../theme';
import { voiceoverSeconds } from '../types';

describe('resolveTheme', () => {
  it('returns the spec defaults for null', () => {
    expect(resolveTheme(null)).toEqual(DEFAULT_THEME);
    expect(DEFAULT_THEME.colors.accent).toBe('#c4744a');
    expect(DEFAULT_THEME.fonts.display).toBe('Bricolage Grotesque');
    expect(DEFAULT_THEME.logo).toBeNull();
  });

  it('merges partial colors and fonts over the defaults', () => {
    const theme = resolveTheme({ colors: { accent: '#ff0000' }, fonts: { mono: 'Fira Code' } });
    expect(theme.colors.accent).toBe('#ff0000');
    expect(theme.colors.done).toBe(DEFAULT_THEME.colors.done);
    expect(theme.fonts.mono).toBe('Fira Code');
    expect(theme.fonts.body).toBe(DEFAULT_THEME.fonts.body);
  });
});

describe('voiceoverSeconds', () => {
  it('reads durationSeconds', () => {
    expect(voiceoverSeconds({ intro: { file: 'vo/intro.mp3', durationSeconds: 9.5 } }, 'intro')).toBe(9.5);
  });

  it('falls back to the legacy duration key', () => {
    expect(voiceoverSeconds({ intro: { file: 'vo/intro.mp3', duration: 9.5 } }, 'intro')).toBe(9.5);
  });

  it('returns null for missing, null and non-positive entries', () => {
    expect(voiceoverSeconds(null, 'intro')).toBeNull();
    expect(voiceoverSeconds({}, 'intro')).toBeNull();
    expect(voiceoverSeconds({ intro: { file: 'vo/intro.mp3', durationSeconds: 0 } }, 'intro')).toBeNull();
  });
});
