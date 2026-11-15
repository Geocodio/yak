import TaskListController from './TaskListController'
import TaskController from './TaskController'
import TaskActionController from './TaskActionController'

const Tasks = {
    TaskListController: Object.assign(TaskListController, TaskListController),
    TaskController: Object.assign(TaskController, TaskController),
    TaskActionController: Object.assign(TaskActionController, TaskActionController),
}

export default Tasks