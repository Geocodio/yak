import { pluralize } from '@/components/riskProfile/RiskProfileStates';

export function OpenQuestions({ unknowns }: { unknowns: string[] }) {
    if (unknowns.length === 0) {
        return null;
    }

    return (
        <section id="open-questions" data-testid="risk-open-questions" className="rounded-card border border-hair bg-panel p-4">
            <div className="flex flex-wrap items-baseline gap-2">
                <h2 className="text-[14px] font-semibold">Open questions</h2>
                <span className="text-[12px] text-muted">{unknowns.length} open</span>
            </div>
            <p className="mt-0.5 text-[12.5px] text-muted">
                Things the profile could not settle from the code. Answer one by editing <span className="font-mono">.yak/risk-profile.yml</span> in a PR.
            </p>
            <ul aria-label={pluralize(unknowns.length, 'open question')} className="mt-3 divide-y divide-hair">
                {unknowns.map((question, position) => (
                    <li key={position} className="flex flex-wrap items-start gap-2 py-2 text-[13px]">
                        <span className="min-w-0 flex-1 break-words">{question}</span>
                        <span className="whitespace-nowrap rounded-control bg-warn-soft px-1.5 py-0.5 text-[11px] font-medium text-text">Blocks auto-approval</span>
                    </li>
                ))}
            </ul>
        </section>
    );
}
