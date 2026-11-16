import { queryParams, type RouteQueryOptions, type RouteDefinition } from './../../../../../../../wayfinder'
/**
* @see \App\Http\Controllers\Api\App\Tasks\TaskListController::__invoke
* @see app/Http/Controllers/Api/App/Tasks/TaskListController.php:12
* @route '/api/app/v1/tasks'
*/
const TaskListController = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: TaskListController.url(options),
    method: 'get',
})

TaskListController.definition = {
    methods: ["get","head"],
    url: '/api/app/v1/tasks',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Api\App\Tasks\TaskListController::__invoke
* @see app/Http/Controllers/Api/App/Tasks/TaskListController.php:12
* @route '/api/app/v1/tasks'
*/
TaskListController.url = (options?: RouteQueryOptions) => {
    return TaskListController.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Api\App\Tasks\TaskListController::__invoke
* @see app/Http/Controllers/Api/App/Tasks/TaskListController.php:12
* @route '/api/app/v1/tasks'
*/
TaskListController.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: TaskListController.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Api\App\Tasks\TaskListController::__invoke
* @see app/Http/Controllers/Api/App/Tasks/TaskListController.php:12
* @route '/api/app/v1/tasks'
*/
TaskListController.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: TaskListController.url(options),
    method: 'head',
})

export default TaskListController