import { registerOverviewSection } from '@/threads/panels';
import { CoOwnerGroup } from './CoOwnerGroup';
import { EnvironmentPanel } from './EnvironmentPanel';
import { NoRepositoryGroups } from './NoRepositoryGroups';
import { RequestGroup } from './RequestGroup';
import { RunDetailsGroup } from './RunDetailsGroup';

registerOverviewSection({ id: 'request', order: 5, component: RequestGroup, visible: () => true });
registerOverviewSection({ id: 'co-owner', order: 10, component: CoOwnerGroup, visible: (thread) => thread.hasRepository });
registerOverviewSection({ id: 'environment', order: 20, component: EnvironmentPanel, visible: (thread) => thread.hasRepository });
registerOverviewSection({ id: 'no-repository', order: 20, component: NoRepositoryGroups, visible: (thread) => !thread.hasRepository });
registerOverviewSection({ id: 'run-details', order: 40, component: RunDetailsGroup, visible: (thread) => thread.hasRepository });
