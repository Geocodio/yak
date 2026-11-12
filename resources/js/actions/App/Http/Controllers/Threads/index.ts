import ThreadMessageController from './ThreadMessageController'
import ContinueTurnController from './ContinueTurnController'
import RestoreRetryController from './RestoreRetryController'
import NewThreadController from './NewThreadController'
import StoreThreadController from './StoreThreadController'
import ThreadController from './ThreadController'
import ThreadListController from './ThreadListController'
import ThreadSeenController from './ThreadSeenController'
import ThreadDiffController from './ThreadDiffController'
import ThreadEnvironmentController from './ThreadEnvironmentController'
import ThreadPullRequestController from './ThreadPullRequestController'
import ThreadEventController from './ThreadEventController'
import ThreadShareController from './ThreadShareController'
import ThreadTeleportRequestController from './ThreadTeleportRequestController'
import Stack from './Stack'
import Preview from './Preview'
import ThreadFileController from './ThreadFileController'
import TerminalSessionController from './TerminalSessionController'
import RepositoryAccessController from './RepositoryAccessController'
import RepositorySetupStatusController from './RepositorySetupStatusController'
import ThreadRepositoryProgressController from './ThreadRepositoryProgressController'
import ThreadRepositoryController from './ThreadRepositoryController'
import PullRequests from './PullRequests'

const Threads = {
    ThreadMessageController: Object.assign(ThreadMessageController, ThreadMessageController),
    ContinueTurnController: Object.assign(ContinueTurnController, ContinueTurnController),
    RestoreRetryController: Object.assign(RestoreRetryController, RestoreRetryController),
    NewThreadController: Object.assign(NewThreadController, NewThreadController),
    StoreThreadController: Object.assign(StoreThreadController, StoreThreadController),
    ThreadController: Object.assign(ThreadController, ThreadController),
    ThreadListController: Object.assign(ThreadListController, ThreadListController),
    ThreadSeenController: Object.assign(ThreadSeenController, ThreadSeenController),
    ThreadDiffController: Object.assign(ThreadDiffController, ThreadDiffController),
    ThreadEnvironmentController: Object.assign(ThreadEnvironmentController, ThreadEnvironmentController),
    ThreadPullRequestController: Object.assign(ThreadPullRequestController, ThreadPullRequestController),
    ThreadEventController: Object.assign(ThreadEventController, ThreadEventController),
    ThreadShareController: Object.assign(ThreadShareController, ThreadShareController),
    ThreadTeleportRequestController: Object.assign(ThreadTeleportRequestController, ThreadTeleportRequestController),
    Stack: Object.assign(Stack, Stack),
    Preview: Object.assign(Preview, Preview),
    ThreadFileController: Object.assign(ThreadFileController, ThreadFileController),
    TerminalSessionController: Object.assign(TerminalSessionController, TerminalSessionController),
    RepositoryAccessController: Object.assign(RepositoryAccessController, RepositoryAccessController),
    RepositorySetupStatusController: Object.assign(RepositorySetupStatusController, RepositorySetupStatusController),
    ThreadRepositoryProgressController: Object.assign(ThreadRepositoryProgressController, ThreadRepositoryProgressController),
    ThreadRepositoryController: Object.assign(ThreadRepositoryController, ThreadRepositoryController),
    PullRequests: Object.assign(PullRequests, PullRequests),
}

export default Threads