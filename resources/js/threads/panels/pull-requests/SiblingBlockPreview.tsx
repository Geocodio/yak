import { ChevronRight } from 'lucide-react';
import { useState } from 'react';

/** The "Related pull requests" text written into this pull request's body. */
export function SiblingBlockPreview({ text, testId }: { text: string; testId: string }) {
    const [isOpen, setIsOpen] = useState(false);

    return (
        <div className="border-t border-hair">
            <button type="button" aria-expanded={isOpen} onClick={() => setIsOpen((value) => !value)} data-testid={testId} className="flex w-full cursor-pointer items-center gap-1.5 px-3 py-2 text-left text-[12.5px] text-muted">
                <ChevronRight size={12} aria-hidden className={isOpen ? 'rotate-90' : ''} />
                What this PR says about the others
            </button>
            {isOpen && <pre className="mx-3 mb-3 whitespace-pre-wrap rounded-chip bg-panel-2 p-2.5 font-sans text-[12px] text-body">{text}</pre>}
        </div>
    );
}
