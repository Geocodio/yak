import TaskListController from './TaskListController'
import StoreTaskController from './StoreTaskController'
import DismissSetupCardController from './DismissSetupCardController'
import TaskController from './TaskController'
import TaskActionController from './TaskActionController'
import TaskMessageController from './TaskMessageController'
import RequestReReviewController from './RequestReReviewController'

const Tasks = {
    TaskListController: Object.assign(TaskListController, TaskListController),
    StoreTaskController: Object.assign(StoreTaskController, StoreTaskController),
    DismissSetupCardController: Object.assign(DismissSetupCardController, DismissSetupCardController),
    TaskController: Object.assign(TaskController, TaskController),
    TaskActionController: Object.assign(TaskActionController, TaskActionController),
    TaskMessageController: Object.assign(TaskMessageController, TaskMessageController),
    RequestReReviewController: Object.assign(RequestReReviewController, RequestReReviewController),
}

export default Tasks