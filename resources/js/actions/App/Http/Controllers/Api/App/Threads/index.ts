import ThreadListController from './ThreadListController'
import ThreadController from './ThreadController'
import ThreadSeenController from './ThreadSeenController'
import ThreadEventController from './ThreadEventController'

const Threads = {
    ThreadListController: Object.assign(ThreadListController, ThreadListController),
    ThreadController: Object.assign(ThreadController, ThreadController),
    ThreadSeenController: Object.assign(ThreadSeenController, ThreadSeenController),
    ThreadEventController: Object.assign(ThreadEventController, ThreadEventController),
}

export default Threads