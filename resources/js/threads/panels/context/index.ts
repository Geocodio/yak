import { registerOverviewSection } from '@/threads/panels';
import { ContextGroup } from './ContextGroup';

registerOverviewSection({ id: 'context', order: 15, component: ContextGroup, visible: () => true });
