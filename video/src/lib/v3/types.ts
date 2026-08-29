/** A focus rectangle in page coordinates, as captured by `yak-browser shoot`. */
export type Rect = { x: number; y: number; w: number; h: number };

export type ScriptShot = {
  id: string;
  chapter: string;
  say: string;
};

export type ScriptTask = {
  id?: number | null;
  repo?: string | null;
  pr?: number | null;
};

export type Script = {
  version?: number;
  task?: ScriptTask | null;
  title: string;
  intro: string;
  summary: string[];
  outro: string;
  shots: ScriptShot[];
};

export type ManifestShot = {
  id: string;
  /** Per-shot clip. Falls back to the manifest-level `clip` when absent. */
  clip?: string | null;
  /** Seconds within the clip where the edit begins. */
  start: number;
  /** Seconds within the clip where the edit ends. */
  end: number;
  rect?: Rect | null;
  url: string;
  still?: string | null;
  phase?: 'before' | 'after' | null;
};

export type Manifest = {
  version?: number;
  width: number;
  height: number;
  base?: string | null;
  /** Shared clip for manifests that record one continuous take. */
  clip?: string | null;
  shots: ManifestShot[];
};

export type VoiceoverEntry = {
  file: string;
  /** Spec §6 key. */
  durationSeconds?: number;
  /** Accepted alias produced by the spike tooling. */
  duration?: number;
};

export type Voiceover = Record<string, VoiceoverEntry> | null;

export type ThemeColors = {
  background: string;
  surface: string;
  ink: string;
  muted: string;
  accent: string;
  done: string;
  captionBg: string;
};

export type ThemeFonts = {
  display: string;
  body: string;
  mono: string;
};

export type Theme = {
  colors: ThemeColors;
  fonts: ThemeFonts;
  /** Absolute path, url or static-file name of the installation logo. */
  logo: string | null;
};

export type PartialTheme = {
  colors?: Partial<ThemeColors>;
  fonts?: Partial<ThemeFonts>;
  logo?: string | null;
};

/** Height of the mock browser bar drawn above every shot (spec §7). */
export const BROWSER_BAR_HEIGHT = 52;

export const DEFAULT_FPS = 30;

/** Duration of a voiceover line, accepting both the spec key and the spike alias. */
export function voiceoverSeconds(voiceover: Voiceover, id: string): number | null {
  const entry = voiceover?.[id];
  if (!entry) {
    return null;
  }
  const seconds = entry.durationSeconds ?? entry.duration;
  return typeof seconds === 'number' && Number.isFinite(seconds) && seconds > 0 ? seconds : null;
}
