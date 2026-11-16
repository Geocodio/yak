import { registerOverviewSection } from '@/threads/panels';
import { EnvironmentPanel } from './EnvironmentPanel';

registerOverviewSection({ id: 'environment', order: 20, component: EnvironmentPanel, visible: () => true });
