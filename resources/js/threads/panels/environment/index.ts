import { registerOverviewSection } from '@/threads/panels';
import { CoOwnerGroup } from './CoOwnerGroup';
import { EnvironmentPanel } from './EnvironmentPanel';
import { RequestGroup } from './RequestGroup';
import { RunDetailsGroup } from './RunDetailsGroup';

registerOverviewSection({ id: 'request', order: 5, component: RequestGroup, visible: () => true });
registerOverviewSection({ id: 'co-owner', order: 10, component: CoOwnerGroup, visible: () => true });
registerOverviewSection({ id: 'environment', order: 20, component: EnvironmentPanel, visible: () => true });
registerOverviewSection({ id: 'run-details', order: 40, component: RunDetailsGroup, visible: () => true });
