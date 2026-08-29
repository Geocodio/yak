import { createServer, type Server } from 'node:http';
import { readFile } from 'node:fs/promises';
import { extname, join, normalize } from 'node:path';

const CONTENT_TYPES: Record<string, string> = {
  '.html': 'text/html; charset=utf-8',
  '.css': 'text/css; charset=utf-8',
  '.js': 'text/javascript; charset=utf-8',
  '.json': 'application/json; charset=utf-8',
};

export type StaticServer = { url: string; close: () => Promise<void> };

export type StaticServerOptions = {
  /** Delay every response by this many ms, to make load time observable. */
  delayMs?: number;
};

/** Serve `root` over http on an ephemeral port. Unknown paths return 404. */
export async function startStaticServer(root: string, options: StaticServerOptions = {}): Promise<StaticServer> {
  const server: Server = createServer(async (req, res) => {
    if (options.delayMs !== undefined && options.delayMs > 0) {
      await new Promise((resolve) => setTimeout(resolve, options.delayMs));
    }
    const raw = decodeURIComponent((req.url ?? '/').split('?')[0]);
    const relative = normalize(raw === '/' ? '/index.html' : raw).replace(/^(\.\.[/\\])+/, '');
    try {
      const body = await readFile(join(root, relative));
      res.writeHead(200, { 'content-type': CONTENT_TYPES[extname(relative)] ?? 'application/octet-stream' });
      res.end(body);
    } catch {
      res.writeHead(404, { 'content-type': 'text/plain' });
      res.end('not found');
    }
  });
  await new Promise<void>((resolve) => server.listen(0, '127.0.0.1', resolve));
  const address = server.address();
  if (address === null || typeof address === 'string') throw new Error('server did not bind a port');
  return {
    url: `http://127.0.0.1:${address.port}`,
    close: () => new Promise<void>((resolve, reject) => server.close((e) => (e ? reject(e) : resolve()))),
  };
}
