import { queryParams, type RouteQueryOptions, type RouteDefinition } from './../../../wayfinder'
/**
* @see \App\Http\Controllers\Api\App\Tasks\TaskListController::__invoke
* @see app/Http/Controllers/Api/App/Tasks/TaskListController.php:12
* @route '/api/app/v1/tasks'
*/
export const index = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: index.url(options),
    method: 'get',
})

index.definition = {
    methods: ["get","head"],
    url: '/api/app/v1/tasks',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Api\App\Tasks\TaskListController::__invoke
* @see app/Http/Controllers/Api/App/Tasks/TaskListController.php:12
* @route '/api/app/v1/tasks'
*/
index.url = (options?: RouteQueryOptions) => {
    return index.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Api\App\Tasks\TaskListController::__invoke
* @see app/Http/Controllers/Api/App/Tasks/TaskListController.php:12
* @route '/api/app/v1/tasks'
*/
index.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: index.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Api\App\Tasks\TaskListController::__invoke
* @see app/Http/Controllers/Api/App/Tasks/TaskListController.php:12
* @route '/api/app/v1/tasks'
*/
index.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: index.url(options),
    method: 'head',
})

const tasks = {
    index: Object.assign(index, index),
}

export default tasks