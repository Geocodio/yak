import ThreadController from './ThreadController'
import ThreadListController from './ThreadListController'
import ThreadSeenController from './ThreadSeenController'
import ThreadDiffController from './ThreadDiffController'
import ThreadEnvironmentController from './ThreadEnvironmentController'
import ThreadEventController from './ThreadEventController'
import ThreadTeleportRequestController from './ThreadTeleportRequestController'
import ThreadMessageController from './ThreadMessageController'
import ContinueTurnController from './ContinueTurnController'
import RestoreRetryController from './RestoreRetryController'
import NewThreadController from './NewThreadController'
import StoreThreadController from './StoreThreadController'
import ThreadShareController from './ThreadShareController'

const Threads = {
    ThreadController: Object.assign(ThreadController, ThreadController),
    ThreadListController: Object.assign(ThreadListController, ThreadListController),
    ThreadSeenController: Object.assign(ThreadSeenController, ThreadSeenController),
    ThreadDiffController: Object.assign(ThreadDiffController, ThreadDiffController),
    ThreadEnvironmentController: Object.assign(ThreadEnvironmentController, ThreadEnvironmentController),
    ThreadEventController: Object.assign(ThreadEventController, ThreadEventController),
    ThreadTeleportRequestController: Object.assign(ThreadTeleportRequestController, ThreadTeleportRequestController),
    ThreadMessageController: Object.assign(ThreadMessageController, ThreadMessageController),
    ContinueTurnController: Object.assign(ContinueTurnController, ContinueTurnController),
    RestoreRetryController: Object.assign(RestoreRetryController, RestoreRetryController),
    NewThreadController: Object.assign(NewThreadController, NewThreadController),
    StoreThreadController: Object.assign(StoreThreadController, StoreThreadController),
    ThreadShareController: Object.assign(ThreadShareController, ThreadShareController),
}

export default Threads