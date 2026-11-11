import ThreadController from './ThreadController'
import ThreadListController from './ThreadListController'
import ThreadSeenController from './ThreadSeenController'
import ThreadDiffController from './ThreadDiffController'
import ThreadEnvironmentController from './ThreadEnvironmentController'
import ThreadPullRequestController from './ThreadPullRequestController'
import ThreadEventController from './ThreadEventController'
import ThreadTeleportRequestController from './ThreadTeleportRequestController'
import ThreadMessageController from './ThreadMessageController'
import ContinueTurnController from './ContinueTurnController'
import RestoreRetryController from './RestoreRetryController'
import NewThreadController from './NewThreadController'
import StoreThreadController from './StoreThreadController'
import ThreadShareController from './ThreadShareController'
import ThreadFileController from './ThreadFileController'
import Preview from './Preview'
import TerminalSessionController from './TerminalSessionController'
import Stack from './Stack'
import PullRequests from './PullRequests'
import RepositoryAccessController from './RepositoryAccessController'
import ThreadRepositoryProgressController from './ThreadRepositoryProgressController'
import ThreadRepositoryController from './ThreadRepositoryController'

const Threads = {
    ThreadController: Object.assign(ThreadController, ThreadController),
    ThreadListController: Object.assign(ThreadListController, ThreadListController),
    ThreadSeenController: Object.assign(ThreadSeenController, ThreadSeenController),
    ThreadDiffController: Object.assign(ThreadDiffController, ThreadDiffController),
    ThreadEnvironmentController: Object.assign(ThreadEnvironmentController, ThreadEnvironmentController),
    ThreadPullRequestController: Object.assign(ThreadPullRequestController, ThreadPullRequestController),
    ThreadEventController: Object.assign(ThreadEventController, ThreadEventController),
    ThreadTeleportRequestController: Object.assign(ThreadTeleportRequestController, ThreadTeleportRequestController),
    ThreadMessageController: Object.assign(ThreadMessageController, ThreadMessageController),
    ContinueTurnController: Object.assign(ContinueTurnController, ContinueTurnController),
    RestoreRetryController: Object.assign(RestoreRetryController, RestoreRetryController),
    NewThreadController: Object.assign(NewThreadController, NewThreadController),
    StoreThreadController: Object.assign(StoreThreadController, StoreThreadController),
    ThreadShareController: Object.assign(ThreadShareController, ThreadShareController),
    ThreadFileController: Object.assign(ThreadFileController, ThreadFileController),
    Preview: Object.assign(Preview, Preview),
    TerminalSessionController: Object.assign(TerminalSessionController, TerminalSessionController),
    Stack: Object.assign(Stack, Stack),
    PullRequests: Object.assign(PullRequests, PullRequests),
    RepositoryAccessController: Object.assign(RepositoryAccessController, RepositoryAccessController),
    ThreadRepositoryProgressController: Object.assign(ThreadRepositoryProgressController, ThreadRepositoryProgressController),
    ThreadRepositoryController: Object.assign(ThreadRepositoryController, ThreadRepositoryController),
}

export default Threads