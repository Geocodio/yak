import { registerSidebarPanel } from '@/threads/panels';
import { TeleportPanel } from '@/threads/panels/teleport/TeleportPanel';

registerSidebarPanel({
    id: 'teleport',
    label: 'Details',
    order: 25,
    component: TeleportPanel,
    visible: (thread) => thread.status === 'open',
});
