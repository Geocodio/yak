import TaskListController from './TaskListController'
import StoreTaskController from './StoreTaskController'
import DismissSetupCardController from './DismissSetupCardController'
import RequestReReviewController from './RequestReReviewController'

const Tasks = {
    TaskListController: Object.assign(TaskListController, TaskListController),
    StoreTaskController: Object.assign(StoreTaskController, StoreTaskController),
    DismissSetupCardController: Object.assign(DismissSetupCardController, DismissSetupCardController),
    RequestReReviewController: Object.assign(RequestReReviewController, RequestReReviewController),
}

export default Tasks