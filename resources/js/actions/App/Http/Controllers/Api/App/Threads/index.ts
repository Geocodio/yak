import ThreadListController from './ThreadListController'
import ThreadController from './ThreadController'
import ThreadSeenController from './ThreadSeenController'

const Threads = {
    ThreadListController: Object.assign(ThreadListController, ThreadListController),
    ThreadController: Object.assign(ThreadController, ThreadController),
    ThreadSeenController: Object.assign(ThreadSeenController, ThreadSeenController),
}

export default Threads