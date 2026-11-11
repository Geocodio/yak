import { Tooltip, cn } from '@geocodio/console-ui';
import { ExternalLink, Lock, Monitor, RefreshCw, Smartphone, Tablet } from 'lucide-react';
import { useEffect, useState, type ComponentType } from 'react';
import type { DeviceWidth } from './previewState';

const DEVICES: { id: DeviceWidth; label: string; icon: ComponentType<{ size?: number }> }[] = [
    { id: 'phone', label: 'Phone width, 390 px', icon: Smartphone },
    { id: 'tablet', label: 'Tablet width, 768 px', icon: Tablet },
    { id: 'desktop', label: 'Full width', icon: Monitor },
];

type Props = {
    host: string | null;
    path: string;
    onPathChange: (path: string) => void;
    onReload: () => void;
    device: DeviceWidth;
    onDeviceChange: (device: DeviceWidth) => void;
    openUrl: string | null;
    isEnabled: boolean;
};

export function PreviewToolbar({ host, path, onPathChange, onReload, device, onDeviceChange, openUrl, isEnabled }: Props) {
    const [draft, setDraft] = useState(path);

    useEffect(() => setDraft(path), [path]);

    return (
        <div className="flex shrink-0 items-center gap-2 border-b border-hair px-3 py-2" data-testid="preview-toolbar">
            <button
                type="button"
                onClick={onReload}
                disabled={!isEnabled}
                aria-label="Reload the preview"
                data-testid="preview-reload"
                className="cursor-pointer text-muted hover:text-body disabled:cursor-default disabled:opacity-40"
            >
                <RefreshCw size={14} />
            </button>

            <form
                className="flex min-w-0 flex-1 items-center gap-2 rounded-chip border border-hair bg-app px-2.5 py-1"
                onSubmit={(event) => {
                    event.preventDefault();
                    onPathChange(draft);
                }}
            >
                <Tooltip label="Only people signed in to Yak can open this address.">
                    <span className="inline-flex shrink-0 text-muted" aria-label="Private address" role="img">
                        <Lock size={12} />
                    </span>
                </Tooltip>
                <span className="hidden min-w-0 truncate font-mono text-[12px] text-muted sm:inline" data-testid="preview-host">
                    {host ?? 'preview'}
                </span>
                <input
                    value={draft}
                    onChange={(event) => setDraft(event.target.value)}
                    disabled={!isEnabled}
                    aria-label="Path"
                    spellCheck={false}
                    className="min-w-0 flex-1 bg-transparent font-mono text-[12px] text-body outline-none"
                    data-testid="preview-path"
                />
            </form>

            <div role="radiogroup" aria-label="Preview width" className="hidden shrink-0 gap-px rounded-chip border border-hair bg-hair sm:flex">
                {DEVICES.map(({ id, label, icon: Icon }) => (
                    <Tooltip key={id} label={label}>
                        <button
                            type="button"
                            role="radio"
                            aria-checked={device === id}
                            aria-label={label}
                            onClick={() => onDeviceChange(id)}
                            data-testid={`preview-width-${id}`}
                            className={cn('cursor-pointer bg-app px-2 py-1', device === id ? 'text-body' : 'text-muted hover:text-body')}
                        >
                            <Icon size={14} />
                        </button>
                    </Tooltip>
                ))}
            </div>

            {openUrl !== null ? (
                <a href={openUrl} target="_blank" rel="noreferrer" aria-label="Open in a new tab" data-testid="preview-open" className="shrink-0 text-muted hover:text-body">
                    <ExternalLink size={14} />
                </a>
            ) : (
                <span className="shrink-0 text-faint" aria-hidden="true">
                    <ExternalLink size={14} />
                </span>
            )}
        </div>
    );
}
