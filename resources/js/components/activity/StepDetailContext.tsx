import { createContext, useContext } from 'react';
import type { StepContent } from './types';

export type StepDetailSource = {
    /** The content of a task log row once fetched, or undefined while it loads. */
    contentFor: (logId: number) => StepContent | undefined;
    /** Asks for the rows' content; requests made close together go out as one. */
    request: (logId: number) => void;
    /** Opens the full log at a row. */
    openInFullLog?: (logId: number) => void;
};

export const StepDetailContext = createContext<StepDetailSource | null>(null);

export function useStepDetailSource(): StepDetailSource | null {
    return useContext(StepDetailContext);
}
