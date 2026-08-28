import { describe, expect, it } from 'vitest';
import { publicUrl } from '../urls';

describe('publicUrl', () => {
  it('substitutes the public origin host and keeps the path', () => {
    expect(publicUrl('http://127.0.0.1:8899/guides/demographics-census', 'https://www.geocod.io')).toEqual({
      host: 'www.geocod.io',
      path: '/guides/demographics-census',
    });
  });

  it('preserves query and hash', () => {
    expect(publicUrl('http://127.0.0.1:8899/search?q=abc#top', 'https://www.geocod.io')).toEqual({
      host: 'www.geocod.io',
      path: '/search?q=abc#top',
    });
  });

  it('returns a path-only display when no public origin is configured', () => {
    expect(publicUrl('http://sandbox-42.internal:8000/updates/', null)).toEqual({
      host: null,
      path: '/updates/',
    });
  });

  it('never leaks the raw host', () => {
    const result = publicUrl('http://sandbox-42.internal:8000/updates/', 'https://www.geocod.io');
    expect(JSON.stringify(result)).not.toContain('sandbox-42');
    expect(JSON.stringify(result)).not.toContain('8000');
  });

  it('prefixes an origin path segment', () => {
    expect(publicUrl('http://127.0.0.1:8899/a', 'https://example.com/docs/')).toEqual({
      host: 'example.com',
      path: '/docs/a',
    });
  });

  it('treats an unparseable url as a path', () => {
    expect(publicUrl('guides/x', null)).toEqual({ host: null, path: '/guides/x' });
  });

  it('does not render a scheme-less host inside the path', () => {
    expect(publicUrl('example.com/a', null)).toEqual({ host: null, path: '/a' });
    expect(publicUrl('example.com/a', 'https://www.geocod.io')).toEqual({
      host: 'www.geocod.io',
      path: '/a',
    });
  });

  it('keeps a dotted relative reference that names no host', () => {
    expect(publicUrl('page.html', null)).toEqual({ host: null, path: '/page.html' });
    expect(publicUrl('guides/x', 'https://www.geocod.io')).toEqual({
      host: 'www.geocod.io',
      path: '/guides/x',
    });
  });

  it('leaves an already absolute path alone', () => {
    expect(publicUrl('/already/absolute', null)).toEqual({ host: null, path: '/already/absolute' });
  });

  it('does not let a scheme-less host:port parse into the path', () => {
    const result = publicUrl('localhost:3000/a', null);
    expect(result).toEqual({ host: null, path: '/a' });
    expect(result.path.startsWith('/')).toBe(true);
    expect(result.path).not.toContain('3000');
  });

  it('falls back to path-only when the public origin is unparseable', () => {
    expect(publicUrl('http://127.0.0.1:8899/a', 'not a url')).toEqual({ host: null, path: '/a' });
  });
});
