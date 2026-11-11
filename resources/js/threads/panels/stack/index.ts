import { registerSidebarPanel } from '@/threads/panels';
import { StackPanel } from './StackPanel';

registerSidebarPanel({
    id: 'stack',
    label: 'Stack',
    order: 15,
    component: StackPanel,
    // The thread payload carries no child count, so every open thread shows the panel; an empty one offers "Add sub-thread".
    visible: (thread) => thread.status === 'open',
});
