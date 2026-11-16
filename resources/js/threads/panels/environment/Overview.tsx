import { X } from 'lucide-react';
import { overviewSectionsFor, type PanelProps } from '@/threads/panels';

/** The Overview rail: one scrolling column of groups, with no tabs. Stack comes first once sub-threads exist. */
export function Overview({ onCloseRail, ...props }: PanelProps) {
    return (
        <div className="flex min-h-0 flex-col" data-testid="overview">
            <div className="flex items-center px-4 pt-3 text-[13px] font-semibold text-body lg:flex">
                <span className="grow">Overview</span>
                {onCloseRail !== undefined && (
                    <button type="button" onClick={onCloseRail} aria-label="Close overview" className="inline-flex size-[26px] cursor-pointer items-center justify-center rounded-[6px] text-muted hover:bg-panel-2 hover:text-body" data-testid="overview-close">
                        <X size={13} aria-hidden />
                    </button>
                )}
            </div>
            {overviewSectionsFor(props.thread).map((section) => (
                <section.component key={section.id} {...props} />
            ))}
        </div>
    );
}
