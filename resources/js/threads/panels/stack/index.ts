import { registerSidebarPanel } from '@/threads/panels';
import { StackBadge } from './StackBadge';
import { StackPanel } from './StackPanel';

registerSidebarPanel({
    id: 'stack',
    label: 'Stack',
    order: 15,
    component: StackPanel,
    badge: StackBadge,
    // The thread payload carries no child count, so every open thread shows the panel; an empty one offers "Add a sub-thread".
    visible: (thread) => thread.status === 'open',
});
