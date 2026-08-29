import { describe, expect, it } from 'vitest';
import { classifySrc, isImageSrc } from '../assets';

describe('classifySrc', () => {
  it('passes remote and file urls through', () => {
    expect(classifySrc('https://example.com/a.webm')).toEqual({ kind: 'url', value: 'https://example.com/a.webm' });
    expect(classifySrc('file:///tmp/a.webm')).toEqual({ kind: 'url', value: 'file:///tmp/a.webm' });
  });

  it('turns an absolute local path into a file url', () => {
    expect(classifySrc('/srv/render/shots/levels.webm')).toEqual({
      kind: 'url',
      value: 'file:///srv/render/shots/levels.webm',
    });
  });

  it('encodes spaces in absolute local paths', () => {
    expect(classifySrc('/srv/my renders/a.webm').value).toBe('file:///srv/my%20renders/a.webm');
  });

  it('treats a bare name as a static file', () => {
    expect(classifySrc('v3/fixture-clip.webm')).toEqual({ kind: 'static', value: 'v3/fixture-clip.webm' });
  });
});

describe('isImageSrc', () => {
  it.each(['a.png', 'a.JPG', 'a.jpeg', 'v3/preview-still.jpg?v=2'])('detects %s as an image', (src) => {
    expect(isImageSrc(src)).toBe(true);
  });

  it.each(['a.webm', 'a.mp4', 'shots/levels.webm'])('detects %s as not an image', (src) => {
    expect(isImageSrc(src)).toBe(false);
  });
});
