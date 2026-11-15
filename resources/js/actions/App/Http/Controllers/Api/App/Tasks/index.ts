import TaskListController from './TaskListController'
import TaskController from './TaskController'
import TaskActionController from './TaskActionController'
import CoOwnerDecisionController from './CoOwnerDecisionController'
import ClarificationAnswerController from './ClarificationAnswerController'

const Tasks = {
    TaskListController: Object.assign(TaskListController, TaskListController),
    TaskController: Object.assign(TaskController, TaskController),
    TaskActionController: Object.assign(TaskActionController, TaskActionController),
    CoOwnerDecisionController: Object.assign(CoOwnerDecisionController, CoOwnerDecisionController),
    ClarificationAnswerController: Object.assign(ClarificationAnswerController, ClarificationAnswerController),
}

export default Tasks