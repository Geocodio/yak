import { router } from '@inertiajs/react';
import { Button, TextInput } from '@geocodio/console-ui';
import { AlertTriangle, CircleDot, Play, RefreshCw } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import type { ReactNode } from 'react';
import { RiskBadge } from '@/components/riskProfile/RiskBadge';
import type { ChangeTestFile, ChangeTestResult, Risk } from '@/types/riskProfile';

const EMPTY_MESSAGE = 'Enter a PR number or a branch name to test.';
const MAX_FILES = 200;

/** Mirrors App\Services\RiskProfileChangeCheck: a positive PR number, or a safe branch name. */
export function isValidChangeInput(input: string): boolean {
    if (/^#?\d{1,9}$/.test(input)) {
        return !/^#?0+$/.test(input);
    }

    return /^[A-Za-z0-9._/-]{1,255}$/.test(input) && !input.includes('..') && !/^[/-]/.test(input) && !input.endsWith('/');
}

function labelFor(input: string): string {
    return /^#?\d+$/.test(input) ? `#${input.replace('#', '')}` : input;
}

function Callout({ role = 'status', tone, icon, children }: { role?: 'status' | 'alert'; tone: 'held' | 'plain'; icon: ReactNode; children: ReactNode }) {
    return (
        <div role={role} className={`flex items-start gap-2 rounded-card px-3 py-2.5 text-[12.5px] leading-snug ${tone === 'held' ? 'bg-warn-soft' : 'bg-panel-2'}`}>
            <span className="mt-0.5 flex-none text-muted">{icon}</span>
            <div className="min-w-0 flex-1 space-y-1">{children}</div>
        </div>
    );
}

function FileList({ files }: { files: ChangeTestFile[] }) {
    return (
        <ul data-testid="change-test-files" className="mt-2 divide-y divide-hair rounded-card border border-hair bg-panel">
            {files.map((file) => (
                <li key={file.path} data-testid="change-test-file" data-held={file.isHeld} className="flex flex-wrap items-center gap-2 px-3 py-1.5 text-[12.5px]">
                    <CircleDot size={10} className={file.isHeld ? 'text-text' : 'text-faint'} aria-hidden />
                    <code className="min-w-0 flex-1 break-all font-mono text-[12px]">{file.path}</code>
                    <span className="flex items-center gap-1.5 text-muted">
                        {file.isFloor ? (
                            <span className="font-medium text-text">Always held</span>
                        ) : file.area ? (
                            <>
                                {file.area.name}
                                <RiskBadge risk={file.area.risk as Risk} />
                            </>
                        ) : (
                            <>
                                {file.isUnchecked ? 'Not checked' : 'No area'}
                                <span className="rounded-control bg-panel-2 px-1.5 py-0.5 text-[11px] font-semibold leading-none text-muted">none</span>
                            </>
                        )}
                    </span>
                </li>
            ))}
        </ul>
    );
}

function Result({ result, onRetry }: { result: ChangeTestResult; onRetry: () => void }) {
    if (result.verdict === 'invalid') {
        return null;
    }

    if (result.verdict === 'no-profile') {
        return <Callout tone="plain" icon={<AlertTriangle size={14} />}><b>There is no profile to test against</b></Callout>;
    }

    if (result.verdict === 'not-found') {
        return <Callout tone="plain" icon={<AlertTriangle size={14} />}><p>{result.message}</p></Callout>;
    }

    if (result.verdict === 'error') {
        return (
            <Callout role="alert" tone="plain" icon={<AlertTriangle size={14} />}>
                <p>{result.message}</p>
                <Button type="button" data-testid="change-test-retry" icon={<RefreshCw size={13} />} onClick={onRetry}>
                    Try again
                </Button>
            </Callout>
        );
    }

    const isHeld = result.verdict === 'held';
    const hasAreas = result.heldAreas.length > 0;

    return (
        <div data-testid="change-test-result" data-verdict={result.verdict}>
            <Callout tone={isHeld ? 'held' : 'plain'} icon={<CircleDot size={14} />}>
                {isHeld ? (
                    <>
                        <p>
                            <b>Would be held:</b> {hasAreas ? result.heldAreas.map((area) => area.name).join(', ') : (result.holdReason ?? '')}{' '}
                            {hasAreas && <RiskBadge risk={result.heldAreas[0].risk as Risk} />}
                            {result.asks.length > 0 && <> Asks <b>{result.asks.join(', ')}</b>.</>}
                        </p>
                        {result.isFloorHeld && hasAreas && <p>Touches a file Yak always holds.</p>}
                    </>
                ) : (
                    <p>
                        <b>Would open as a normal PR:</b> {result.files.some((file) => file.area) ? 'no held area' : 'no changed file matches any area'}
                        {!result.files.some((file) => file.area) && (
                            <>
                                {' '}
                                <span className="rounded-control bg-panel px-1.5 py-0.5 text-[11px] font-semibold leading-none text-muted">No area</span>
                            </>
                        )}
                    </p>
                )}
                {result.capMessage && result.capMessage !== result.holdReason && <p data-testid="change-test-cap">{result.capMessage}</p>}
                {!isHeld && !result.files.some((file) => file.area) && (
                    <p>
                        No area, so Yak does not hold it. If these files are risky, add an area for them in <code className="font-mono">.yak/risk-profile.yml</code>.
                    </p>
                )}
                {result.gateMode === 'off' && <p>The co-owner gate is off in this repository, so Yak opens this PR today. This is what enforce would do.</p>}
            </Callout>
            <FileList files={result.files} />
            {result.files.length === MAX_FILES && <p className="mt-1.5 text-[12px] text-muted">Showing {MAX_FILES} of the changed files.</p>}
        </div>
    );
}

export function ChangeTester({ result = null, branch }: { result?: ChangeTestResult | null; branch: string }) {
    const [input, setInput] = useState(() => (typeof window === 'undefined' ? '' : (new URLSearchParams(window.location.search).get('change') ?? '')));
    const [validationMessage, setValidationMessage] = useState<string | null>(null);
    const [pendingLabel, setPendingLabel] = useState<string | null>(null);
    const hasRestored = useRef(false);

    const request = (value: string) => {
        setPendingLabel(labelFor(value));
        router.reload({
            only: ['changeTest'],
            data: { change: value },
            onFinish: () => setPendingLabel(null),
        });
    };

    useEffect(() => {
        if (hasRestored.current) {
            return;
        }
        hasRestored.current = true;

        if (input.trim() !== '' && !result) {
            request(input.trim());
        }
        // Runs once on mount to restore a linked result.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    const submit = () => {
        const value = input.trim();

        if (!isValidChangeInput(value)) {
            setValidationMessage(EMPTY_MESSAGE);

            return;
        }

        setValidationMessage(null);
        request(value);
    };

    const clear = () => {
        setInput('');
        setValidationMessage(null);
        router.reload({ only: ['changeTest'], data: { change: undefined } });
    };

    const message = validationMessage ?? (result?.verdict === 'invalid' && pendingLabel === null ? (result.message ?? EMPTY_MESSAGE) : null);
    const isBusy = pendingLabel !== null;

    return (
        <section data-testid="change-test" className="grid gap-4 rounded-card border border-hair bg-panel p-4 md:grid-cols-[minmax(0,2fr)_minmax(0,3fr)]">
            <form
                onSubmit={(event) => {
                    event.preventDefault();
                    submit();
                }}
            >
                <h3 className="text-[13px] font-semibold">Test against a change</h3>
                <div className="mt-2 flex items-center gap-2">
                    <TextInput
                        data-testid="change-test-input"
                        aria-label="PR number or branch"
                        aria-invalid={message !== null}
                        placeholder="PR or branch"
                        value={input}
                        maxLength={255}
                        onChange={(event) => setInput(event.target.value)}
                        className="min-w-0 flex-1"
                    />
                    <Button type="submit" data-testid="change-test-submit" disabled={isBusy} icon={isBusy ? <RefreshCw size={13} /> : <Play size={13} />}>
                        {isBusy ? 'Testing…' : 'Test'}
                    </Button>
                    <Button type="button" variant="tertiary" data-testid="change-test-clear" onClick={clear}>
                        Clear
                    </Button>
                </div>
                {message && (
                    <p data-testid="change-test-error" role="alert" className="mt-1.5 flex items-center gap-1 text-[12px] text-warn">
                        <AlertTriangle size={12} aria-hidden />
                        {message}
                    </p>
                )}
                <p className="mt-1.5 text-[12px] text-muted">A branch name or PR number. Reads the profile on {branch}. Nothing is saved.</p>
            </form>
            <div className="min-w-0">
                {isBusy ? (
                    <div data-testid="change-test-loading">
                        <Callout tone="plain" icon={<RefreshCw size={14} />}>
                            <b>Fetching the files in {pendingLabel} from GitHub…</b>
                            <p className="text-muted">Then matching each path against the areas on {branch}.</p>
                        </Callout>
                    </div>
                ) : result && result.verdict !== 'invalid' ? (
                    <Result result={result} onRetry={() => request(result.input)} />
                ) : (
                    <p className="pt-6 text-[12.5px] leading-normal text-faint">
                        The result appears here. It shows whether the change would be held and which areas it touches.
                    </p>
                )}
            </div>
        </section>
    );
}
