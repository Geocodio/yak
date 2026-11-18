import TaskRedirectController from './TaskRedirectController'
import PullRequestImageController from './PullRequestImageController'

const Tasks = {
    TaskRedirectController: Object.assign(TaskRedirectController, TaskRedirectController),
    PullRequestImageController: Object.assign(PullRequestImageController, PullRequestImageController),
}

export default Tasks