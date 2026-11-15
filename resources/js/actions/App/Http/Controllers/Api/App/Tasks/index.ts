import TaskListController from './TaskListController'
import TaskController from './TaskController'
import EarlyWarningController from './EarlyWarningController'
import TaskActionController from './TaskActionController'
import CoOwnerDecisionController from './CoOwnerDecisionController'
import ClarificationAnswerController from './ClarificationAnswerController'

const Tasks = {
    TaskListController: Object.assign(TaskListController, TaskListController),
    TaskController: Object.assign(TaskController, TaskController),
    EarlyWarningController: Object.assign(EarlyWarningController, EarlyWarningController),
    TaskActionController: Object.assign(TaskActionController, TaskActionController),
    CoOwnerDecisionController: Object.assign(CoOwnerDecisionController, CoOwnerDecisionController),
    ClarificationAnswerController: Object.assign(ClarificationAnswerController, ClarificationAnswerController),
}

export default Tasks