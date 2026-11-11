import type { TeleportData, ThreadComposer, ThreadNotice } from '@/types/threads';

/** Composer copy while a teleport is requested or the thread is on a computer. */
export const teleportComposerHint = {
    waiting: 'Yak keeps working until you run the command on your computer.',
    onDevice: (name: string) => `This thread is on ${name}. Teleport it to Yak to message Claude here.`,
};

const DEFAULT = { placeholder: 'Message Claude', hint: 'Enter to send, Shift+Enter for a new line' };

const savedFor = (when: string) => ({
    placeholder: `Message Claude. It reads this ${when}.`,
    hint: `Enter to send. Claude reads it ${when}.`,
});

/** The composer placeholder and the hint line beside the attach button for the thread's current state. */
export function composerCopy(notice: ThreadNotice | null, state: ThreadComposer['state'], teleportState: TeleportData['state']): { placeholder: string; hint: string } {
    if (teleportState === 'waiting') {
        return { ...DEFAULT, hint: teleportComposerHint.waiting };
    }
    if (teleportState === 'on_device') {
        return { ...DEFAULT, hint: teleportComposerHint.onDevice('another computer') };
    }
    switch (notice?.kind) {
        case 'restoring':
            return savedFor('once the sandbox is back');
        case 'restore_failed':
            return { placeholder: 'Message Claude. It reads this after you retry.', hint: 'Enter to send. Claude reads it after you retry.' };
        case 'waiting_for_account':
            return savedFor('when its turn starts');
        case 'waiting_for_sandbox':
            return savedFor('when the sandbox is up');
    }
    if (state === 'working') {
        return { ...DEFAULT, placeholder: 'Add a message. Claude reads it as soon as it can.' };
    }
    return DEFAULT;
}
