import { spawnSync } from 'node:child_process';

export type DomRect = { left: number; top: number; width: number; height: number };

/**
 * Ask agent-browser to evaluate a small JS snippet that returns the bounding rect
 * of the first element matching the selector. Returns null if the element is not found
 * or if agent-browser errors.
 */
export function captureRect(selector: string, agentBrowserPath: string): DomRect | null {
  const script = `(() => {
    const el = document.querySelector(${JSON.stringify(selector)});
    if (!el) return null;
    const r = el.getBoundingClientRect();
    return { left: Math.round(r.left), top: Math.round(r.top), width: Math.round(r.width), height: Math.round(r.height) };
  })()`;
  const result = spawnSync(agentBrowserPath, ['eval', script], { encoding: 'utf8' });
  if (result.status !== 0) return null;
  try {
    const parsed = JSON.parse(result.stdout.trim());
    if (!parsed) return null;
    if (typeof parsed.left !== 'number') return null;
    return parsed;
  } catch {
    return null;
  }
}
