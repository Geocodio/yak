import { Select, cn } from '@geocodio/console-ui';
import { Loader2 } from 'lucide-react';
import { useState } from 'react';
import { Prose } from '@/components/Prose';
import type { PromptFixtureOption, PromptPreview } from '@/types/prompts';

type ViewMode = 'rendered' | 'raw';

export function PreviewPane({
    preview,
    loading = false,
    fixtures,
    fixtureIndex,
    onFixtureChange,
}: {
    preview: PromptPreview;
    /** A preview request is in flight; the body below is the previous result. */
    loading?: boolean;
    fixtures: PromptFixtureOption[];
    fixtureIndex: number;
    onFixtureChange: (index: number) => void;
}) {
    const [mode, setMode] = useState<ViewMode>('rendered');

    return (
        <div className="flex min-h-0 flex-col bg-sidebar">
            <div className="flex items-center justify-between gap-2 border-b border-hair px-3 py-1.5">
                <div className="flex items-center gap-3">
                    <span className="text-[11px] font-semibold uppercase tracking-wide text-faint">Preview</span>
                    {loading && (
                        <span className="flex items-center gap-1 text-[11px] text-faint" data-testid="preview-loading">
                            <Loader2 size={11} className="animate-spin" />
                            Updating
                        </span>
                    )}
                    {preview.ok && (
                        <div className="flex overflow-hidden rounded-chip border border-hair" role="tablist" aria-label="Preview mode">
                            {(['rendered', 'raw'] as const).map((option) => (
                                <button
                                    key={option}
                                    type="button"
                                    role="tab"
                                    aria-selected={mode === option}
                                    onClick={() => setMode(option)}
                                    data-testid={`preview-mode-${option}`}
                                    className={cn(
                                        'px-2 py-0.5 text-[11px] capitalize transition',
                                        mode === option ? 'bg-accent-soft text-accent-text' : 'text-faint hover:text-body',
                                    )}
                                >
                                    {option}
                                </button>
                            ))}
                        </div>
                    )}
                </div>
                {fixtures.length > 1 ? (
                    <Select
                        className="h-6 min-w-[200px] text-[11px]"
                        options={fixtures.map((f) => ({ value: String(f.index), label: f.label }))}
                        value={String(fixtureIndex)}
                        onChange={(value) => onFixtureChange(Number(value))}
                        aria-label="Change sample"
                        data-testid="fixture-select"
                    />
                ) : fixtures.length === 1 ? (
                    <span className="text-[11px] text-faint">Sample: {fixtures[0].label}</span>
                ) : null}
            </div>
            <div className={cn('min-h-0 flex-1 overflow-auto p-4 transition-opacity', loading && 'opacity-60')}>
                {preview.ok ? (
                    mode === 'rendered' ? (
                        <div data-testid="prompt-preview">
                            <Prose html={preview.bodyHtml ?? ''} className="text-[13px]" />
                        </div>
                    ) : (
                        <pre className="whitespace-pre-wrap break-words font-mono text-[12.5px] leading-relaxed text-body" data-testid="prompt-preview">
                            {preview.body}
                        </pre>
                    )
                ) : (
                    <pre className="whitespace-pre-wrap break-words font-mono text-[12.5px] leading-relaxed text-fail" data-testid="prompt-preview-error">
                        {preview.error}
                    </pre>
                )}
            </div>
        </div>
    );
}
