import React from 'react';
import type { DisplayUrl } from '../../lib/v3/urls';
import type { Theme } from '../../lib/v3/types';
import { BROWSER_BAR_HEIGHT } from '../../lib/v3/types';
import type { ResolvedFonts } from './useThemeFonts';

export type BrowserBarProps = {
  url: DisplayUrl;
  width: number;
  theme: Theme;
  fonts: ResolvedFonts;
};

const TRAFFIC_LIGHTS = ['#E5645A', '#E9B33F', '#5FC26B'];

/**
 * Mock browser chrome above every shot. The host is only ever the configured
 * public origin; when there is none, the pill shows the path alone.
 */
export const BrowserBar: React.FC<BrowserBarProps> = ({ url, width, theme, fonts }) => (
  <div
    style={{
      position: 'absolute',
      top: 0,
      left: 0,
      width,
      height: BROWSER_BAR_HEIGHT,
      background: '#ECEAE4',
      borderBottom: '1px solid #D6D3CB',
      display: 'flex',
      alignItems: 'center',
      padding: '0 16px',
      gap: 14,
      fontFamily: fonts.body,
      boxSizing: 'border-box',
    }}
  >
    <div style={{ display: 'flex', gap: 7 }}>
      {TRAFFIC_LIGHTS.map((color) => (
        <div key={color} style={{ width: 12, height: 12, borderRadius: 6, background: color }} />
      ))}
    </div>
    <div style={{ display: 'flex', gap: 12, color: '#9A978F', fontSize: 18, marginLeft: 4 }}>
      <span>&#8249;</span>
      <span>&#8250;</span>
      <span style={{ fontSize: 15 }}>&#8635;</span>
    </div>
    <div
      style={{
        flex: 1,
        height: 32,
        background: '#FBFAF7',
        borderRadius: 8,
        border: '1px solid #DAD7CF',
        display: 'flex',
        alignItems: 'center',
        padding: '0 12px',
        gap: 8,
        fontSize: 15,
        color: theme.colors.ink,
        overflow: 'hidden',
        whiteSpace: 'nowrap',
      }}
    >
      <span
        style={{
          width: 9,
          height: 11,
          borderRadius: 2,
          border: `2px solid ${theme.colors.done}`,
          borderTopLeftRadius: 6,
          borderTopRightRadius: 6,
          flex: '0 0 auto',
        }}
      />
      {url.host === null ? null : <span style={{ fontWeight: 700 }}>{url.host}</span>}
      <span style={{ color: theme.colors.muted }}>{url.path}</span>
    </div>
  </div>
);
