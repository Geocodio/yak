import { Head, router, useForm } from '@inertiajs/react';
import { Badge, Button, ConfirmDialog, toast } from '@geocodio/console-ui';
import { AlertTriangle, Clock, GitCompare, RotateCcw, Save } from 'lucide-react';
import { useEffect, useMemo, useRef, useState, type ReactNode } from 'react';
import { AppLayout } from '@/layouts/AppLayout';
import { PageHeader } from '@/components/PageHeader';
import { CodeEditor } from '@/components/editor/CodeEditor';
import { DiffEditor } from '@/components/editor/DiffEditor';
import { HistoryDialog } from '@/components/prompts/HistoryDialog';
import { PreviewPane } from '@/components/prompts/PreviewPane';
import { PromptSidebar } from '@/components/prompts/PromptSidebar';
import prompts from '@/routes/prompts';
import type { PageProps } from '@/types/shared';
import type { PromptDetail, PromptFixtureOption, PromptGroup, PromptPreview, PromptVersionRow } from '@/types/prompts';

type Props = PageProps<{
    prompts: PromptGroup[];
    prompt: PromptDetail;
    fixtures: PromptFixtureOption[];
    fixtureIndex: number;
    preview: PromptPreview;
    versions: PromptVersionRow[];
}>;

function readCsrfCookie(): string {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);
    return match ? decodeURIComponent(match[1]) : '';
}

/** Mirrors `PromptController::unusedVariables()`/`unknownVariables()` so the warning row updates on every keystroke, not just on page load. */
function computeVariableWarnings(content: string, variables: string[]): { unused: string[]; unknown: string[] } {
    const unused = variables.filter((v) => !new RegExp(`\\$${v}\\b`).test(content));

    const found = new Set<string>();
    const echoPattern = /\{\{\s*\$([A-Za-z_][A-Za-z0-9_]*)\b|\{!!\s*\$([A-Za-z_][A-Za-z0-9_]*)\b/g;
    let m: RegExpExecArray | null;
    while ((m = echoPattern.exec(content)) !== null) {
        found.add(m[1] ?? m[2]);
    }
    const unknown = Array.from(found).filter((v) => !variables.includes(v));

    return { unused, unknown };
}

export default function Index({ prompts: groups, prompt, fixtures, fixtureIndex: initialFixtureIndex, preview: initialPreview, versions }: Props) {
    const [content, setContent] = useState(prompt.content);
    const [fixtureIndex, setFixtureIndex] = useState(initialFixtureIndex);
    const [preview, setPreview] = useState<PromptPreview>(initialPreview);
    const [showDiff, setShowDiff] = useState(false);
    const [showHistory, setShowHistory] = useState(false);
    const [showReset, setShowReset] = useState(false);
    const [resetBusy, setResetBusy] = useState(false);

    useEffect(() => {
        setContent(prompt.content);
        setFixtureIndex(initialFixtureIndex);
        setPreview(initialPreview);
        setShowDiff(false);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [prompt.slug]);

    const form = useForm({ content: prompt.content });

    const save = () => {
        form.transform(() => ({ content }));
        form.put(prompts.update.url(prompt.slug), { preserveScroll: true });
    };

    const debounceRef = useRef<ReturnType<typeof setTimeout> | null>(null);
    useEffect(() => {
        if (debounceRef.current) {
            clearTimeout(debounceRef.current);
        }
        debounceRef.current = setTimeout(() => {
            fetch(prompts.preview.url(prompt.slug), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-XSRF-TOKEN': readCsrfCookie(),
                },
                body: JSON.stringify({ content, fixture: fixtureIndex }),
            })
                .then((response) => response.json())
                .then((data: PromptPreview) => setPreview(data))
                .catch(() => undefined);
        }, 400);

        return () => {
            if (debounceRef.current) {
                clearTimeout(debounceRef.current);
            }
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [content, fixtureIndex, prompt.slug]);

    const warnings = useMemo(() => computeVariableWarnings(content, prompt.variables), [content, prompt.variables]);

    const loadVersion = (version: PromptVersionRow) => {
        fetch(prompts.versions.show.url([prompt.slug, version.id]), { headers: { Accept: 'application/json' } })
            .then((response) => response.json())
            .then((data: { content: string }) => {
                setContent(data.content);
                setShowHistory(false);
                toast.info(`Loaded version ${version.number} (unsaved).`);
            });
    };

    const resetToDefault = () => {
        setResetBusy(true);
        router.delete(prompts.reset.url(prompt.slug), {
            preserveScroll: true,
            onFinish: () => {
                setResetBusy(false);
                setShowReset(false);
            },
        });
    };

    return (
        <>
            <Head title={`${prompt.label} · Prompts`} />
            <PageHeader
                crumbs={['Prompts', prompt.label]}
                actions={
                    <>
                        <Button variant="tertiary" icon={<Clock size={13} />} onClick={() => setShowHistory(true)}>
                            History
                        </Button>
                        <Button icon={<GitCompare size={13} />} onClick={() => setShowDiff((v) => !v)} data-testid="toggle-diff">
                            {showDiff ? 'Hide diff' : 'Diff'}
                        </Button>
                        {prompt.customized && (
                            <Button variant="tertiary" className="text-fail" onClick={() => setShowReset(true)} data-testid="reset-button">
                                Reset
                            </Button>
                        )}
                        <Button variant="primary" icon={<Save size={13} />} pending={form.processing} onClick={save} data-testid="save-button">
                            Save <span className="ml-1.5 text-[10px] opacity-70">⌘S</span>
                        </Button>
                    </>
                }
            />

            <div className="flex min-h-0 flex-1" data-testid="prompt-editor">
                <PromptSidebar groups={groups} activeSlug={prompt.slug} />

                <div className="flex min-w-0 flex-1 flex-col">
                    <div className="flex flex-wrap items-center gap-2 border-b border-hair px-4 py-2">
                        <span className="text-[13px] font-medium">{prompt.label}</span>
                        <Badge>{prompt.type}</Badge>
                        {prompt.customized && <Badge tone="accent">Customized</Badge>}
                        {form.errors.content && (
                            <span className="text-[11px] text-fail" data-testid="save-message">
                                {form.errors.content}
                            </span>
                        )}
                        {prompt.variables.length > 0 && (
                            <>
                                <span className="ml-4 text-[11px] text-faint">Variables</span>
                                {prompt.variables.map((v) => (
                                    <code key={v} className="rounded-chip bg-panel-2 px-1.5 py-0.5 font-mono text-[11px] text-muted">
                                        ${v}
                                    </code>
                                ))}
                            </>
                        )}
                        {warnings.unknown.length > 0 && (
                            <span className="ml-auto flex items-center gap-1.5 text-[11px] text-fail">
                                <AlertTriangle size={12} /> Unknown variables in prompt: {warnings.unknown.map((v) => `$${v}`).join(', ')}
                            </span>
                        )}
                        {warnings.unknown.length === 0 && warnings.unused.length > 0 && (
                            <span className="ml-auto flex items-center gap-1.5 text-[11px] text-warn">
                                <AlertTriangle size={12} /> Unused variables: {warnings.unused.map((v) => `$${v}`).join(', ')}
                            </span>
                        )}
                    </div>

                    <div className="grid min-h-0 flex-1 grid-cols-[1fr_minmax(320px,40%)]">
                        <div className="min-h-0 border-r border-hair">
                            {showDiff ? (
                                <div className="flex h-full min-h-0 flex-col" data-testid="prompt-diff">
                                    <div className="grid grid-cols-2 border-b border-hair bg-sidebar text-[11px] font-semibold uppercase tracking-wide text-faint">
                                        <div className="px-3 py-1.5">Shipped default</div>
                                        <div className="border-l border-hair px-3 py-1.5">Current draft</div>
                                    </div>
                                    <DiffEditor before={prompt.defaultContent} after={content} />
                                </div>
                            ) : (
                                <CodeEditor value={content} onChange={setContent} variables={prompt.variables} onSave={save} data-testid="prompt-editor-surface" />
                            )}
                        </div>

                        <PreviewPane preview={preview} fixtures={fixtures} fixtureIndex={fixtureIndex} onFixtureChange={setFixtureIndex} />
                    </div>
                </div>
            </div>

            <HistoryDialog open={showHistory} onOpenChange={setShowHistory} label={prompt.label} versions={versions} onLoad={loadVersion} />

            <ConfirmDialog
                open={showReset}
                onOpenChange={setShowReset}
                title="Reset to default?"
                body="This discards your customized content and restores the shipped default. Saved versions are kept -- you can restore an edit from History later."
                confirmLabel="Reset"
                destructive
                busy={resetBusy}
                confirmTestId="reset-confirm"
                onConfirm={resetToDefault}
            />
        </>
    );
}

Index.layout = (page: ReactNode) => <AppLayout>{page}</AppLayout>;
