import { spawnSync } from 'node:child_process';

export function captureCurrentUrl(agentBrowserPath: string): string | null {
  const result = spawnSync(agentBrowserPath, ['get', 'url'], { encoding: 'utf8' });
  if (result.status !== 0) return null;
  const out = (result.stdout ?? '').trim();
  return out.length > 0 ? out : null;
}
