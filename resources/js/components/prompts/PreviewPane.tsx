import { Select } from '@geocodio/console-ui';
import type { PromptFixtureOption, PromptPreview } from '@/types/prompts';

export function PreviewPane({
    preview,
    fixtures,
    fixtureIndex,
    onFixtureChange,
}: {
    preview: PromptPreview;
    fixtures: PromptFixtureOption[];
    fixtureIndex: number;
    onFixtureChange: (index: number) => void;
}) {
    return (
        <div className="flex min-h-0 flex-col bg-sidebar">
            <div className="flex items-center justify-between border-b border-hair px-3 py-1.5">
                <span className="text-[11px] font-semibold uppercase tracking-wide text-faint">Preview</span>
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
            <div className="min-h-0 flex-1 overflow-auto p-4">
                {preview.ok ? (
                    <pre className="whitespace-pre-wrap break-words font-mono text-[12.5px] leading-relaxed text-body" data-testid="prompt-preview">
                        {preview.body}
                    </pre>
                ) : (
                    <pre className="whitespace-pre-wrap break-words font-mono text-[12.5px] leading-relaxed text-fail" data-testid="prompt-preview-error">
                        {preview.error}
                    </pre>
                )}
            </div>
        </div>
    );
}
