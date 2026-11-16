import { registerSidebarPanel } from '@/threads/panels';
import { EnvironmentPanel } from './EnvironmentPanel';

registerSidebarPanel({ id: 'environment', label: 'Environment', order: 10, component: EnvironmentPanel, visible: () => true });
