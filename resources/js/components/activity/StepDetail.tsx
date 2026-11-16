import { cn } from '@geocodio/console-ui';
import { formatStepDuration } from '@/components/tasks/groupActivity';
import { useStepDetailSource } from './StepDetailContext';
import type { StepView } from './types';

/** The word in front of a step's label: `$` for a command, the tool's name otherwise. */
export function stepPrefix(step: Pick<StepView, 'category' | 'tool'>): string | null {
    if (step.category === 'thinking') {
        return null;
    }

    if (step.tool === null || step.tool === 'Bash') {
        return '$';
    }

    return step.tool === 'WebFetch' ? 'Fetch' : step.tool;
}

const ERROR_LINE = /\b(error|errors|fail|failed|failure|fatal|exception|denied|unauthori[sz]ed|forbidden|cannot|can't|missing|not found|expected)\b/i;

/** Output with the lines that report a problem tinted, when the step itself failed. */
function Output({ text, failed }: { text: string; failed: boolean }) {
    return (
        <pre className="m-0 max-h-[190px] overflow-auto border-t border-hair px-[11px] py-[9px] font-mono text-[12px] leading-[1.55] wrap-anywhere whitespace-pre-wrap text-muted">
            {text.split('\n').map((line, index, lines) => (
                <span key={index} className={cn(failed && ERROR_LINE.test(line) && 'text-fail')}>
                    {line}
                    {index < lines.length - 1 ? '\n' : ''}
                </span>
            ))}
        </pre>
    );
}

/**
 * What an opened step shows: the full command with its scrollable output, or
 * the thought, and a footer with the tool, exit code, duration, time and a
 * link into the full log. Content a thread carries is shown as given; a
 * task's is fetched by the row's log id.
 */
export function StepDetail({ step }: { step: StepView }) {
    const source = useStepDetailSource();
    const content = step.content ?? (step.logId !== null ? source?.contentFor(step.logId) : undefined);
    const isThinking = step.category === 'thinking';
    const prefix = stepPrefix(step);
    const duration = formatStepDuration(step.durationMs);
    const canOpenFullLog = step.logId !== null && source?.openInFullLog !== undefined;

    const footer = [
        isThinking ? 'Thinking' : (step.tool ?? 'Command'),
        step.exitCode !== null ? `exit ${step.exitCode}` : null,
        step.running ? 'running' : duration,
        step.at,
    ]
        .filter(Boolean)
        .join(' · ');

    return (
        <div className="my-1 mr-0 mb-2 ml-9 min-w-0 overflow-hidden rounded-lg border border-hair bg-app" data-testid="step-detail">
            {isThinking ? (
                <div className="px-3 py-2.5 text-[13px] leading-[1.55] text-body whitespace-pre-wrap">{content?.thought ?? step.label}</div>
            ) : (
                <>
                    <pre className="m-0 px-[11px] py-[9px] font-mono text-[12px] leading-[1.55] wrap-anywhere whitespace-pre-wrap">
                        {prefix && <span className="text-faint">{prefix} </span>}
                        {step.tool === null || step.tool === 'Bash' ? (content?.input ?? step.label) : step.label}
                    </pre>
                    {content?.output ? (
                        <Output text={content.output} failed={step.failed} />
                    ) : (
                        <p className="m-0 border-t border-hair px-[11px] py-[9px] text-[12px] text-faint">
                            {step.running ? 'Running. Output appears when the command finishes.' : content || !source ? 'No output.' : 'Loading output…'}
                        </p>
                    )}
                </>
            )}
            <div className="tnum flex flex-wrap items-center justify-between gap-x-2.5 gap-y-1 border-t border-hair px-[11px] py-1.5 text-[11.5px] text-faint">
                <span>{footer}</span>
                {canOpenFullLog && (
                    <button type="button" onClick={() => source?.openInFullLog?.(step.logId as number)} className="cursor-pointer font-medium text-accent-text hover:underline">
                        Open in full log
                    </button>
                )}
            </div>
        </div>
    );
}
