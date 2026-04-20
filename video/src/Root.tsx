import { Composition, staticFile } from 'remotion';
import { Walkthrough, WalkthroughProps, walkthroughCompositionDuration } from './compositions/Walkthrough';
import { FakeUI } from './compositions/FakeUI';
import exampleStoryboard from '../fixtures/example-storyboard.json';
import type { Storyboard } from './lib/storyboard';

const FPS = 30;

export const RemotionRoot = () => (
  <>
    <Composition
      id="FakeUI"
      component={FakeUI}
      fps={FPS}
      width={1280}
      height={720}
      durationInFrames={FPS * 15}
    />
    <Composition<Record<string, unknown>, WalkthroughProps>
      id="Walkthrough"
      component={Walkthrough}
      fps={FPS}
      width={1280}
      height={720}
      durationInFrames={FPS * 15}
      defaultProps={{
        videoUrl: staticFile('example-walkthrough.webm'),
        storyboard: exampleStoryboard as Storyboard,
        videoDurationSeconds: null,
        musicTrack: null,
        tier: 'reviewer',
      }}
      calculateMetadata={({ props }) => {
        const seconds = walkthroughCompositionDuration(props.storyboard, props.videoDurationSeconds);
        const withTail = Math.max(seconds, 5);
        return { durationInFrames: Math.round(withTail * FPS) };
      }}
    />
  </>
);
