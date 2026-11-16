import { registerOverviewSection } from '@/threads/panels';
import { StackGroup } from './StackGroup';

// The group renders nothing until the thread has sub-threads or is one, so it is safe to register for every thread.
registerOverviewSection({ id: 'stack', order: 10, component: StackGroup, visible: (thread) => thread.status === 'open' });
