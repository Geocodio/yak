import { queryParams, type RouteQueryOptions, type RouteDefinition } from './../../../../../wayfinder'
/**
* @see \App\Http\Controllers\Tasks\TaskListController::__invoke
* @see app/Http/Controllers/Tasks/TaskListController.php:30
* @route '/tasks'
*/
const TaskListController = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: TaskListController.url(options),
    method: 'get',
})

TaskListController.definition = {
    methods: ["get","head"],
    url: '/tasks',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Tasks\TaskListController::__invoke
* @see app/Http/Controllers/Tasks/TaskListController.php:30
* @route '/tasks'
*/
TaskListController.url = (options?: RouteQueryOptions) => {
    return TaskListController.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Tasks\TaskListController::__invoke
* @see app/Http/Controllers/Tasks/TaskListController.php:30
* @route '/tasks'
*/
TaskListController.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: TaskListController.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Tasks\TaskListController::__invoke
* @see app/Http/Controllers/Tasks/TaskListController.php:30
* @route '/tasks'
*/
TaskListController.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: TaskListController.url(options),
    method: 'head',
})

export default TaskListController