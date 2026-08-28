import { Composition, staticFile } from 'remotion';
import { Walkthrough, walkthroughCompositionDuration } from './compositions/Walkthrough';
import { FakeUI } from './compositions/FakeUI';
import exampleStoryboard from '../fixtures/example-storyboard.json';
import type { Storyboard } from './lib/storyboard';
import { WalkthroughV3, walkthroughV3Metadata } from './compositions/WalkthroughV3';
import type { Manifest, Script } from './lib/v3/types';
import v3Manifest from '../fixtures/v3/manifest.json';
import v3Script from '../fixtures/v3/script.json';
import { SAMPLE_PROPS } from './preview/sample';

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
    <Composition
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
    <Composition
      id="WalkthroughV3"
      component={WalkthroughV3}
      fps={FPS}
      width={1440}
      height={952}
      durationInFrames={FPS * 30}
      defaultProps={{
        script: v3Script as Script,
        manifest: v3Manifest as Manifest,
        voiceover: null,
        theme: null,
        publicOrigin: 'https://www.example.com',
      }}
      calculateMetadata={walkthroughV3Metadata}
    />
    <Composition
      id="PreviewWalkthrough"
      component={WalkthroughV3}
      fps={FPS}
      width={1440}
      height={952}
      durationInFrames={FPS * 20}
      defaultProps={SAMPLE_PROPS}
      calculateMetadata={walkthroughV3Metadata}
    />
  </>
);
