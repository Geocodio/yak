import { AbsoluteFill, Sequence, useVideoConfig } from 'remotion';

const appFont = '"Inter", -apple-system, sans-serif';

const DashboardScene = () => (
  <AbsoluteFill style={{ background: '#1e1e2e', fontFamily: appFont }}>
    <div style={{ padding: '40px 60px', borderBottom: '1px solid #2a2a3e' }}>
      <div style={{ color: '#7c5cff', fontSize: 28, fontWeight: 700 }}>Yak</div>
    </div>
    <div style={{ padding: '60px 60px 40px' }}>
      <div style={{ color: '#f5f5f7', fontSize: 42, fontWeight: 600 }}>Dashboard</div>
      <div style={{ color: '#888899', fontSize: 20, marginTop: 8 }}>/tasks</div>
    </div>
    <div style={{ padding: '0 60px', display: 'flex', gap: 16 }}>
      <div
        style={{
          background: '#7c5cff',
          color: 'white',
          padding: '16px 32px',
          borderRadius: 10,
          fontSize: 20,
          fontWeight: 600,
          boxShadow: '0 6px 24px rgba(124, 92, 255, 0.35)',
        }}
      >
        + Create Task
      </div>
      <div
        style={{
          background: '#2a2a3e',
          color: '#dddddd',
          padding: '16px 32px',
          borderRadius: 10,
          fontSize: 20,
        }}
      >
        Filter
      </div>
    </div>
    <div style={{ padding: '40px 60px' }}>
      <div style={{ color: '#888899', fontSize: 16, marginBottom: 12 }}>No tasks yet</div>
    </div>
  </AbsoluteFill>
);

const FormScene = () => (
  <AbsoluteFill style={{ background: '#1e1e2e', fontFamily: appFont }}>
    <div style={{ padding: '40px 60px', borderBottom: '1px solid #2a2a3e' }}>
      <div style={{ color: '#7c5cff', fontSize: 28, fontWeight: 700 }}>Yak</div>
    </div>
    <div style={{ padding: '80px 200px 40px' }}>
      <div style={{ color: '#f5f5f7', fontSize: 36, fontWeight: 600 }}>New Task</div>
      <div style={{ color: '#888899', fontSize: 16, marginTop: 8 }}>Describe what you want done</div>
    </div>
    <div style={{ padding: '0 200px' }}>
      <div style={{ color: '#bbbbbb', fontSize: 14, marginBottom: 8 }}>Task description</div>
      <div
        style={{
          background: '#2a2a3e',
          padding: '18px 20px',
          borderRadius: 10,
          fontSize: 18,
          color: '#f5f5f7',
          border: '1px solid #3a3a4e',
        }}
      >
        Add dark mode toggle to the header
      </div>
    </div>
    <div style={{ padding: '40px 200px 0', display: 'flex', gap: 12, justifyContent: 'flex-end' }}>
      <div style={{ color: '#888899', padding: '14px 28px', fontSize: 18 }}>Cancel</div>
      <div
        style={{
          background: '#7c5cff',
          color: 'white',
          padding: '14px 32px',
          borderRadius: 10,
          fontSize: 18,
          fontWeight: 600,
        }}
      >
        Submit
      </div>
    </div>
  </AbsoluteFill>
);

const ResultScene = () => (
  <AbsoluteFill style={{ background: '#1e1e2e', fontFamily: appFont }}>
    <div style={{ padding: '40px 60px', borderBottom: '1px solid #2a2a3e' }}>
      <div style={{ color: '#7c5cff', fontSize: 28, fontWeight: 700 }}>Yak</div>
    </div>
    <div style={{ padding: '60px 60px 40px' }}>
      <div style={{ color: '#f5f5f7', fontSize: 42, fontWeight: 600 }}>Dashboard</div>
      <div style={{ color: '#888899', fontSize: 20, marginTop: 8 }}>/tasks</div>
    </div>
    <div style={{ padding: '0 60px' }}>
      <div
        style={{
          background: '#2a2a3e',
          padding: '24px 28px',
          borderRadius: 12,
          border: '1px solid #3a3a4e',
        }}
      >
        <div style={{ color: '#f5f5f7', fontSize: 22, fontWeight: 600 }}>
          Add dark mode toggle to the header
        </div>
        <div style={{ color: '#7c5cff', fontSize: 16, marginTop: 10, fontWeight: 500 }}>
          Status: In progress
        </div>
        <div style={{ color: '#888899', fontSize: 14, marginTop: 4 }}>created just now</div>
      </div>
    </div>
  </AbsoluteFill>
);

export const FakeUI = () => {
  const { fps } = useVideoConfig();
  return (
    <AbsoluteFill style={{ background: '#000' }}>
      <Sequence from={0} durationInFrames={fps * 5}>
        <DashboardScene />
      </Sequence>
      <Sequence from={fps * 5} durationInFrames={fps * 5}>
        <FormScene />
      </Sequence>
      <Sequence from={fps * 10} durationInFrames={fps * 5}>
        <ResultScene />
      </Sequence>
    </AbsoluteFill>
  );
};
