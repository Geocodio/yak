/** The question to show: the chosen one, or the last one when a newer round has fewer questions. */
export function visibleStep(step: number, count: number): number {
    return Math.max(0, Math.min(step, count - 1));
}

/** The index of the first question, in card order, that has an answer error of any kind; -1 when none has. */
export function firstQuestionWithError(questionIds: string[], errorKeys: string[]): number {
    return questionIds.findIndex((id) => errorKeys.some((key) => key === `answers.${id}` || key.startsWith(`answers.${id}.`)));
}
