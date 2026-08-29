export type DisplayUrl = {
  /** Host to render in bold, or null when no public origin is configured. */
  host: string | null;
  /** Path, query and hash, always starting with a slash. */
  path: string;
};

/** A `scheme://` prefix — the only form `new URL` parses into a real host. */
const ABSOLUTE_URL = /^[a-z][a-z0-9+.-]*:\/\//i;

/**
 * A leading authority on a scheme-less reference. `example.com/a` and
 * `localhost:3000/a` both name a host, and `new URL` either rejects them or
 * (for `localhost:3000/a`) mis-parses the host into the path, so the host is
 * dropped rather than rendered. The trailing `/` is required: it is what makes
 * the input `host/path` rather than a path. A lone dotted segment such as
 * `page.html` is a filename and survives intact.
 */
const LEADING_AUTHORITY = /^(?:[^/?#]*\.[^/?#]*|localhost)(?::\d+)?(?=\/)/i;

function pathOf(raw: string): string {
  if (ABSOLUTE_URL.test(raw)) {
    try {
      const url = new URL(raw);
      return `${url.pathname}${url.search}${url.hash}`;
    } catch {
      return '/';
    }
  }
  const relative = raw.replace(LEADING_AUTHORITY, '');
  return relative.startsWith('/') ? relative : `/${relative}`;
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
