import ThreadListController from './ThreadListController'
import ThreadController from './ThreadController'
import ThreadMessageController from './ThreadMessageController'
import ClarificationAnswerController from './ClarificationAnswerController'
import ThreadSeenController from './ThreadSeenController'
import QueuedMessageController from './QueuedMessageController'
import ThreadEventController from './ThreadEventController'

const Threads = {
    ThreadListController: Object.assign(ThreadListController, ThreadListController),
    ThreadController: Object.assign(ThreadController, ThreadController),
    ThreadMessageController: Object.assign(ThreadMessageController, ThreadMessageController),
    ClarificationAnswerController: Object.assign(ClarificationAnswerController, ClarificationAnswerController),
    ThreadSeenController: Object.assign(ThreadSeenController, ThreadSeenController),
    QueuedMessageController: Object.assign(QueuedMessageController, QueuedMessageController),
    ThreadEventController: Object.assign(ThreadEventController, ThreadEventController),
}

export default Threads