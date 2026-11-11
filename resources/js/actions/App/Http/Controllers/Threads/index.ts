import ThreadController from './ThreadController'
import ThreadListController from './ThreadListController'
import ThreadSeenController from './ThreadSeenController'

const Threads = {
    ThreadController: Object.assign(ThreadController, ThreadController),
    ThreadListController: Object.assign(ThreadListController, ThreadListController),
    ThreadSeenController: Object.assign(ThreadSeenController, ThreadSeenController),
}

export default Threads