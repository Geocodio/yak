import type { TeleportData, ThreadComposer, ThreadNotice } from '@/types/threads';

/** Composer copy while a teleport is requested or the thread is on a computer. */
export const teleportComposerHint = {
    waiting: 'Yak keeps working until you run the command on your computer.',
    onDevice: (name: string) => `This thread is on ${name}. Teleport it to Yak to message Claude here.`,
};

export const DEFAULT_HINT = 'Enter to send, Shift+Enter for a new line';
const DEFAULT = { placeholder: 'Message Claude', hint: DEFAULT_HINT, isDefaultHint: true };

const savedFor = (when: string) => ({
    isDefaultHint: false,
    placeholder: `Message Claude. It reads this ${when}.`,
    hint: `Enter to send. Claude reads it ${when}.`,
});

/** The composer placeholder and the hint line beside the attach button for the thread's current state. `isDefaultHint` is true while the hint is the plain send hint, which a channel hint may replace. */
export function composerCopy(notice: ThreadNotice | null, state: ThreadComposer['state'], teleportState: TeleportData['state'], isOwnerless = false): { placeholder: string; hint: string; isDefaultHint: boolean } {
    if (teleportState === 'waiting') {
        return { ...DEFAULT, hint: teleportComposerHint.waiting, isDefaultHint: false };
    }
    if (teleportState === 'on_device') {
        return { ...DEFAULT, hint: teleportComposerHint.onDevice('another computer'), isDefaultHint: false };
    }
    switch (notice?.kind) {
        case 'restoring':
            return savedFor('once the sandbox is back');
        case 'usage_limit':
            return { ...DEFAULT, placeholder: 'Message Claude. It reads this after the limit resets.' };
        case 'waiting_for_account':
            return savedFor('when its turn starts');
        case 'waiting_for_repository':
        case 'repository_setup_failed':
            return { placeholder: 'This thread starts when its repository is set up.', hint: 'Messages open once the thread has started.', isDefaultHint: false };
        case 'waiting_for_sandbox':
            return savedFor('when the sandbox is up');
    }
    if (state === 'legacy') {
        return { placeholder: 'Message Claude', hint: 'This work is from before threads. Send a message to continue it in a new thread.', isDefaultHint: false };
    }
    if (state === 'stopped') {
        return { placeholder: 'Write a note for Yak…', hint: `Send a note and Yak tries again with it. Or press Retry above.${isOwnerless ? ' Your first message makes you the owner.' : ''}`, isDefaultHint: false };
    }
    if (state === 'working') {
        return { ...DEFAULT, placeholder: 'Add a message. Claude reads it as soon as it can.' };
    }
    return DEFAULT;
}

/**
 * The line that says where replies to the request land, shown while the composer has nothing else to say:
 * the issue or thread it came from, or how the first message of a thread nobody owns yet makes its sender the owner.
 */
export function channelHint(source: { key: string; label: string }, isOwnerless: boolean): string | null {
    if (isOwnerless) {
        return 'Your first message makes you the owner.';
    }
    switch (source.key) {
        case 'linear':
        case 'github':
            return `Replies here and in the ${source.label} issue land in this thread.`;
        case 'slack':
            return 'Replies here and in the Slack thread land in this thread.';
        case 'email':
            return 'Replies here and by email land in this thread.';
        default:
            return null;
    }
}
