import ThreadMessageController from './ThreadMessageController'
import ThreadStepLimitController from './ThreadStepLimitController'
import ThreadRepositoryAnswerController from './ThreadRepositoryAnswerController'
import ThreadTaskActionController from './ThreadTaskActionController'
import ThreadCoOwnerController from './ThreadCoOwnerController'
import ThreadQueuedMessageController from './ThreadQueuedMessageController'
import ContinueTurnController from './ContinueTurnController'
import RestoreRetryController from './RestoreRetryController'
import ThreadLogController from './ThreadLogController'
import NewThreadController from './NewThreadController'
import StoreThreadController from './StoreThreadController'
import ThreadController from './ThreadController'
import ThreadListController from './ThreadListController'
import ThreadSeenController from './ThreadSeenController'
import ThreadStarController from './ThreadStarController'
import ThreadCloseController from './ThreadCloseController'
import ThreadDestroyController from './ThreadDestroyController'
import ThreadSharedFileController from './ThreadSharedFileController'
import ThreadDiffController from './ThreadDiffController'
import ThreadRunLogController from './ThreadRunLogController'
import LocalTurnsController from './LocalTurnsController'
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
    ThreadStepLimitController: Object.assign(ThreadStepLimitController, ThreadStepLimitController),
    ThreadRepositoryAnswerController: Object.assign(ThreadRepositoryAnswerController, ThreadRepositoryAnswerController),
    ThreadTaskActionController: Object.assign(ThreadTaskActionController, ThreadTaskActionController),
    ThreadCoOwnerController: Object.assign(ThreadCoOwnerController, ThreadCoOwnerController),
    ThreadQueuedMessageController: Object.assign(ThreadQueuedMessageController, ThreadQueuedMessageController),
    ContinueTurnController: Object.assign(ContinueTurnController, ContinueTurnController),
    RestoreRetryController: Object.assign(RestoreRetryController, RestoreRetryController),
    ThreadLogController: Object.assign(ThreadLogController, ThreadLogController),
    NewThreadController: Object.assign(NewThreadController, NewThreadController),
    StoreThreadController: Object.assign(StoreThreadController, StoreThreadController),
    ThreadController: Object.assign(ThreadController, ThreadController),
    ThreadListController: Object.assign(ThreadListController, ThreadListController),
    ThreadSeenController: Object.assign(ThreadSeenController, ThreadSeenController),
    ThreadStarController: Object.assign(ThreadStarController, ThreadStarController),
    ThreadCloseController: Object.assign(ThreadCloseController, ThreadCloseController),
    ThreadDestroyController: Object.assign(ThreadDestroyController, ThreadDestroyController),
    ThreadSharedFileController: Object.assign(ThreadSharedFileController, ThreadSharedFileController),
    ThreadDiffController: Object.assign(ThreadDiffController, ThreadDiffController),
    ThreadRunLogController: Object.assign(ThreadRunLogController, ThreadRunLogController),
    LocalTurnsController: Object.assign(LocalTurnsController, LocalTurnsController),
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