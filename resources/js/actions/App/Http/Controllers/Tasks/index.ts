import TaskListController from './TaskListController'
import StoreTaskController from './StoreTaskController'
import DismissSetupCardController from './DismissSetupCardController'
import TaskController from './TaskController'
import TaskActionController from './TaskActionController'
import CoOwnerDecisionController from './CoOwnerDecisionController'
import TaskMessageController from './TaskMessageController'
import QueuedMessageController from './QueuedMessageController'
import TaskClarificationAnswerController from './TaskClarificationAnswerController'
import RequestReReviewController from './RequestReReviewController'
import PullRequestImageController from './PullRequestImageController'

const Tasks = {
    TaskListController: Object.assign(TaskListController, TaskListController),
    StoreTaskController: Object.assign(StoreTaskController, StoreTaskController),
    DismissSetupCardController: Object.assign(DismissSetupCardController, DismissSetupCardController),
    TaskController: Object.assign(TaskController, TaskController),
    TaskActionController: Object.assign(TaskActionController, TaskActionController),
    CoOwnerDecisionController: Object.assign(CoOwnerDecisionController, CoOwnerDecisionController),
    TaskMessageController: Object.assign(TaskMessageController, TaskMessageController),
    QueuedMessageController: Object.assign(QueuedMessageController, QueuedMessageController),
    TaskClarificationAnswerController: Object.assign(TaskClarificationAnswerController, TaskClarificationAnswerController),
    RequestReReviewController: Object.assign(RequestReReviewController, RequestReReviewController),
    PullRequestImageController: Object.assign(PullRequestImageController, PullRequestImageController),
}

export default Tasks