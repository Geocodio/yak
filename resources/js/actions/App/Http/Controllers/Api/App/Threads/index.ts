import ThreadListController from './ThreadListController'
import NewThreadController from './NewThreadController'
import ThreadController from './ThreadController'
import ThreadMessageController from './ThreadMessageController'
import ClarificationAnswerController from './ClarificationAnswerController'
import ThreadShareController from './ThreadShareController'
import ThreadSeenController from './ThreadSeenController'
import ThreadActionController from './ThreadActionController'
import QueuedMessageController from './QueuedMessageController'
import ThreadEventController from './ThreadEventController'
import ThreadDiffController from './ThreadDiffController'
import PullRequestController from './PullRequestController'
import ThreadStepLimitController from './ThreadStepLimitController'
import ThreadAgentController from './ThreadAgentController'
import StackController from './StackController'
import ThreadEnvironmentController from './ThreadEnvironmentController'
import ThreadRepositoryController from './ThreadRepositoryController'
import Preview from './Preview'
import TerminalSessionController from './TerminalSessionController'
import ThreadFileController from './ThreadFileController'
import ThreadCoOwnerController from './ThreadCoOwnerController'
import ThreadTaskActionController from './ThreadTaskActionController'
import ThreadRepositoryAnswerController from './ThreadRepositoryAnswerController'
import ThreadManagementController from './ThreadManagementController'

const Threads = {
    ThreadListController: Object.assign(ThreadListController, ThreadListController),
    NewThreadController: Object.assign(NewThreadController, NewThreadController),
    ThreadController: Object.assign(ThreadController, ThreadController),
    ThreadMessageController: Object.assign(ThreadMessageController, ThreadMessageController),
    ClarificationAnswerController: Object.assign(ClarificationAnswerController, ClarificationAnswerController),
    ThreadShareController: Object.assign(ThreadShareController, ThreadShareController),
    ThreadSeenController: Object.assign(ThreadSeenController, ThreadSeenController),
    ThreadActionController: Object.assign(ThreadActionController, ThreadActionController),
    QueuedMessageController: Object.assign(QueuedMessageController, QueuedMessageController),
    ThreadEventController: Object.assign(ThreadEventController, ThreadEventController),
    ThreadDiffController: Object.assign(ThreadDiffController, ThreadDiffController),
    PullRequestController: Object.assign(PullRequestController, PullRequestController),
    ThreadStepLimitController: Object.assign(ThreadStepLimitController, ThreadStepLimitController),
    ThreadAgentController: Object.assign(ThreadAgentController, ThreadAgentController),
    StackController: Object.assign(StackController, StackController),
    ThreadEnvironmentController: Object.assign(ThreadEnvironmentController, ThreadEnvironmentController),
    ThreadRepositoryController: Object.assign(ThreadRepositoryController, ThreadRepositoryController),
    Preview: Object.assign(Preview, Preview),
    TerminalSessionController: Object.assign(TerminalSessionController, TerminalSessionController),
    ThreadFileController: Object.assign(ThreadFileController, ThreadFileController),
    ThreadCoOwnerController: Object.assign(ThreadCoOwnerController, ThreadCoOwnerController),
    ThreadTaskActionController: Object.assign(ThreadTaskActionController, ThreadTaskActionController),
    ThreadRepositoryAnswerController: Object.assign(ThreadRepositoryAnswerController, ThreadRepositoryAnswerController),
    ThreadManagementController: Object.assign(ThreadManagementController, ThreadManagementController),
}

export default Threads