import type { TeleportData } from '@/types/threads';

/** Whether the header menu and the command palette offer Teleport. */
export const canTeleportThread = (teleport: TeleportData): boolean => teleport.canTeleport && teleport.state !== 'teleporting';
