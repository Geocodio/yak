import { Prose } from '@/components/Prose';
import type { LegacyAnswer, ThreadMessage, ThreadMilestone } from '@/types/threads';
import { PersonMessage } from './PersonMessage';
import { RunFooter } from './RunFooter';

type Entry = { at: string; message: ThreadMessage } | { at: string; answer: LegacyAnswer };

/** The stored history of a thread made from an old task: the person's messages and Yak's answers, in time order. */
export function LegacyConversation({ messages, answers, milestones = [], onOpenLog }: { messages: ThreadMessage[]; answers: LegacyAnswer[]; milestones?: ThreadMilestone[]; onOpenLog?: (taskId: string) => void }) {
    // A task's milestones sit after its last answer.
    const lastAnswerOfTask = new Map(answers.map((answer) => [answer.runTaskId, answer]));
    const entries: Entry[] = [...messages.map((message) => ({ at: message.sentAt, message })), ...answers.map((answer) => ({ at: answer.at, answer }))].sort((a, b) => Date.parse(a.at) - Date.parse(b.at));

    return (
        <div className="min-h-0 flex-1 space-y-5 overflow-y-auto px-4 py-4" data-testid="legacy-conversation">
            {entries.map((entry) =>
                'message' in entry ? (
                    <PersonMessage key={entry.message.id} message={entry.message} pending={false} onOpenMedia={() => {}} />
                ) : (
                    <div key={`${entry.answer.runTaskId}-${entry.at}`} className="flex gap-3" data-testid="legacy-answer">
                        <img src="/mascot-avatar.png" alt="" className="mt-0.5 h-6 w-6 shrink-0 rounded-pill" />
                        <div className="min-w-0 flex-1">
                            <div className="flex items-center gap-2 text-[12px] font-medium text-body">
                                Yak
                                <span className="font-normal text-faint">{new Date(entry.at).toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' })}</span>
                            </div>
                            <Prose html={entry.answer.html} className="mt-1" />
                            {onOpenLog !== undefined && (
                                <RunFooter
                                    milestones={lastAnswerOfTask.get(entry.answer.runTaskId) === entry.answer ? milestones.filter((milestone) => milestone.runId === entry.answer.runTaskId) : []}
                                    onOpenLog={() => onOpenLog(entry.answer.runTaskId)}
                                />
                            )}
                        </div>
                    </div>
                ),
            )}
        </div>
    );
}
