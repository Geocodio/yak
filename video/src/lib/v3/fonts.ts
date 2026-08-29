import { loadFont as loadArchivo } from '@remotion/google-fonts/Archivo';
import { loadFont as loadBricolageGrotesque } from '@remotion/google-fonts/BricolageGrotesque';
import { loadFont as loadDMSans } from '@remotion/google-fonts/DMSans';
import { loadFont as loadFigtree } from '@remotion/google-fonts/Figtree';
import { loadFont as loadFiraCode } from '@remotion/google-fonts/FiraCode';
import { loadFont as loadFraunces } from '@remotion/google-fonts/Fraunces';
import { loadFont as loadGeist } from '@remotion/google-fonts/Geist';
import { loadFont as loadGeistMono } from '@remotion/google-fonts/GeistMono';
import { loadFont as loadIBMPlexMono } from '@remotion/google-fonts/IBMPlexMono';
import { loadFont as loadIBMPlexSans } from '@remotion/google-fonts/IBMPlexSans';
import { loadFont as loadInstrumentSans } from '@remotion/google-fonts/InstrumentSans';
import { loadFont as loadInstrumentSerif } from '@remotion/google-fonts/InstrumentSerif';
import { loadFont as loadInter } from '@remotion/google-fonts/Inter';
import { loadFont as loadJetBrainsMono } from '@remotion/google-fonts/JetBrainsMono';
import { loadFont as loadLora } from '@remotion/google-fonts/Lora';
import { loadFont as loadManrope } from '@remotion/google-fonts/Manrope';
import { loadFont as loadOutfit } from '@remotion/google-fonts/Outfit';
import { loadFont as loadPlayfairDisplay } from '@remotion/google-fonts/PlayfairDisplay';
import { loadFont as loadRobotoMono } from '@remotion/google-fonts/RobotoMono';
import { loadFont as loadSora } from '@remotion/google-fonts/Sora';
import { loadFont as loadSourceCodePro } from '@remotion/google-fonts/SourceCodePro';
import { loadFont as loadSourceSerif4 } from '@remotion/google-fonts/SourceSerif4';
import { loadFont as loadSpaceGrotesk } from '@remotion/google-fonts/SpaceGrotesk';
import { loadFont as loadWorkSans } from '@remotion/google-fonts/WorkSans';

export type FontRole = 'display' | 'body' | 'mono';

/**
 * Every theme font resolves to "<google family>, <system stack>". The stack is
 * what renders if the Google Fonts fetch is unavailable, and it is the whole
 * value when the theme names a family this project does not bundle.
 */
export const FONT_FALLBACKS: Record<FontRole, string> = {
  display: '"Avenir Next", "Helvetica Neue", Helvetica, Arial, sans-serif',
  body: '"Helvetica Neue", Helvetica, Arial, sans-serif',
  mono: 'ui-monospace, Menlo, Consolas, "Courier New", monospace',
};

type FontLoader = () => { fontFamily: string };

/**
 * A theme may name any of these Google families. Dynamic imports are avoided
 * on purpose: the bundler would have to pull in all 1,800 Google font modules.
 */
const REGISTRY: Record<string, FontLoader> = {
  Archivo: loadArchivo,
  'Bricolage Grotesque': loadBricolageGrotesque,
  'DM Sans': loadDMSans,
  Figtree: loadFigtree,
  'Fira Code': loadFiraCode,
  Fraunces: loadFraunces,
  Geist: loadGeist,
  'Geist Mono': loadGeistMono,
  'IBM Plex Mono': loadIBMPlexMono,
  'IBM Plex Sans': loadIBMPlexSans,
  'Instrument Sans': loadInstrumentSans,
  'Instrument Serif': loadInstrumentSerif,
  Inter: loadInter,
  'JetBrains Mono': loadJetBrainsMono,
  Lora: loadLora,
  Manrope: loadManrope,
  Outfit: loadOutfit,
  'Playfair Display': loadPlayfairDisplay,
  'Roboto Mono': loadRobotoMono,
  Sora: loadSora,
  'Source Code Pro': loadSourceCodePro,
  'Source Serif 4': loadSourceSerif4,
  'Space Grotesk': loadSpaceGrotesk,
  'Work Sans': loadWorkSans,
};

/** Families the theme page may offer. */
export function supportedFontFamilies(): string[] {
  return Object.keys(REGISTRY).sort();
}

/**
 * Resolve a theme font family to a CSS font-family value. An unknown family,
 * or a loader that cannot run, degrades to the role's system stack; it never
 * fails a render.
 */
export function themeFontFamily(family: string | null | undefined, role: FontRole): string {
  const fallback = FONT_FALLBACKS[role];
  const loader = family ? REGISTRY[family] : undefined;
  if (!loader) {
    return fallback;
  }
  try {
    const { fontFamily } = loader();
    return `"${fontFamily}", ${fallback}`;
  } catch {
    return fallback;
  }
}
