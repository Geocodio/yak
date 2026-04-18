import { Composition } from 'remotion';

export const RemotionRoot = () => (
  <Composition
    id="Walkthrough"
    component={() => (
      <div
        style={{
          background: '#111',
          width: '100%',
          height: '100%',
          color: 'white',
          display: 'flex',
          alignItems: 'center',
          justifyContent: 'center',
          fontSize: 48,
        }}
      >
        placeholder
      </div>
    )}
    durationInFrames={150}
    fps={30}
    width={1280}
    height={720}
  />
);
