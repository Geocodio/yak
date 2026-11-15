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
import Preview from './Preview'
import TerminalSessionController from './TerminalSessionController'
import ThreadFileController from './ThreadFileController'

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
    Preview: Object.assign(Preview, Preview),
    TerminalSessionController: Object.assign(TerminalSessionController, TerminalSessionController),
    ThreadFileController: Object.assign(ThreadFileController, ThreadFileController),
}

export default Threads