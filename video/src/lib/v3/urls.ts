export type DisplayUrl = {
  /** Host to render in bold, or null when no public origin is configured. */
  host: string | null;
  /** Path, query and hash, always starting with a slash. */
  path: string;
};

function pathOf(raw: string): string {
  try {
    const url = new URL(raw);
    return `${url.pathname}${url.search}${url.hash}`;
  } catch {
    return raw.startsWith('/') ? raw : `/${raw}`;
  }
}

/**
 * Build the URL shown in the browser bar. The manifest's raw host (a sandbox
 * or localhost address) is never rendered: either the configured public
 * origin's host is substituted, or only the path is shown.
 */
export function publicUrl(raw: string, publicOrigin: string | null): DisplayUrl {
  const path = pathOf(raw);
  if (!publicOrigin) {
    return { host: null, path };
  }
  try {
    const origin = new URL(publicOrigin);
    const prefix = origin.pathname.replace(/\/+$/, '');
    return { host: origin.host, path: `${prefix}${path}` };
  } catch {
    return { host: null, path };
  }
}
