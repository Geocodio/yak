import { Audio } from 'remotion';

export const MusicBed = ({ src }: { src: string | null }) => {
  if (!src) return null;
  return <Audio src={src} volume={0.15} />;
};
