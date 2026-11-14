import type { TeleportData } from '@/types/threads';

/** Whether the Environment panel and the command palette offer Teleport. */
export const canTeleportThread = (teleport: TeleportData): boolean => teleport.canTeleport && teleport.state !== 'teleporting';

/** The Teleport label for the thread's current side. */
export const teleportLabel = (teleport: TeleportData): string => (teleport.state === 'on_device' ? 'Teleport to Yak' : 'Teleport to your computer');
