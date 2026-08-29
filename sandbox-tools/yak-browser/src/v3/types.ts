/** One physical or synthetic step inside a shot's `do` list (spec §4). */
export type Action = {
  navigate?: string;
  scroll_to?: string;
  click?: string;
  fill?: string;
  value?: string;
  type?: string;
  press?: string;
  wait?: string | number;
  hover?: string;
};

/** Action keys that count as "physical" for the at-least-one-action rule. */
export const PHYSICAL_ACTION_KEYS = ['navigate', 'scroll_to', 'click', 'fill', 'type', 'press', 'hover'] as const;

/** Every recognised action key, including `wait` and `fill`'s `value`. */
export const ACTION_KEYS = [...PHYSICAL_ACTION_KEYS, 'wait', 'value'] as const;

/** Action keys whose value is a Playwright selector. */
export const SELECTOR_ACTION_KEYS = ['scroll_to', 'click', 'fill', 'hover'] as const;

export type Shot = {
  id: string;
  chapter: string;
  say: string;
  do: Action[];
  focus?: string;
};

export type ScreenshotSpec = {
  id: string;
  caption: string;
  after_shot?: string;
  do?: Action[];
};

export type Script = {
  version: number;
  title: string;
  intro: string;
  summary: string[];
  outro: string;
  shots: Shot[];
  screenshots: ScreenshotSpec[];
};

export type Rect = { x: number; y: number; w: number; h: number };

export type ManifestShot = {
  id: string;
  clip: string;
  start: number;
  end: number;
  rect: Rect | null;
  url: string;
  still: string;
};

export type ManifestScreenshot = {
  id: string;
  file: string;
  caption: string;
};

export type Manifest = {
  version: 3;
  width: number;
  height: number;
  base: string;
  shots: ManifestShot[];
  screenshots: ManifestScreenshot[];
};
