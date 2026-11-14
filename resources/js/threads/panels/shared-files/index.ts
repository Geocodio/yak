import { registerOverviewSection } from '@/threads/panels';
import { SharedFilesGroup } from './SharedFilesGroup';

registerOverviewSection({ id: 'shared-files', order: 30, component: SharedFilesGroup, visible: () => true });
