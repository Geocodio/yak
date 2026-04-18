export type Plan = {
  tier: 'reviewer' | 'director';
  goal: string;
  chapters: Array<{ title: string; beats: string[] }>;
  expected_duration_seconds: number;
  emphasize_budget: number;
  callout_budget: number;
  fastforward_segments: Array<unknown>;
};

export type StoryboardEvent =
  | { t: number; type: 'chapter'; title: string }
  | { t: number; type: 'narrate'; text: string }
  | {
      t: number;
      type: 'callout';
      text: string;
      selector: string;
      anchor?: 'top' | 'bottom' | 'left' | 'right';
      rect?: { left: number; top: number; width: number; height: number };
    }
  | { t: number; type: 'emphasize' }
  | { t: number; type: 'fastforward'; start: boolean; factor?: number }
  | { t: number; type: 'note'; text: string }
  | { t: number; type: 'click'; x: number; y: number; selector?: string }
  | { t: number; type: 'keypress'; keys: string }
  | { t: number; type: 'navigate'; url: string };

export type Storyboard = { version: 1; plan: Plan; events: StoryboardEvent[] };
