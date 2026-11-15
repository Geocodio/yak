import { queryParams, type RouteQueryOptions, type RouteDefinition } from './../../../../../../../wayfinder'
/**
* @see \App\Http\Controllers\Api\App\Threads\ThreadListController::__invoke
* @see app/Http/Controllers/Api/App/Threads/ThreadListController.php:12
* @route '/api/app/v1/threads'
*/
const ThreadListController = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: ThreadListController.url(options),
    method: 'get',
})

ThreadListController.definition = {
    methods: ["get","head"],
    url: '/api/app/v1/threads',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Api\App\Threads\ThreadListController::__invoke
* @see app/Http/Controllers/Api/App/Threads/ThreadListController.php:12
* @route '/api/app/v1/threads'
*/
ThreadListController.url = (options?: RouteQueryOptions) => {
    return ThreadListController.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Api\App\Threads\ThreadListController::__invoke
* @see app/Http/Controllers/Api/App/Threads/ThreadListController.php:12
* @route '/api/app/v1/threads'
*/
ThreadListController.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: ThreadListController.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Api\App\Threads\ThreadListController::__invoke
* @see app/Http/Controllers/Api/App/Threads/ThreadListController.php:12
* @route '/api/app/v1/threads'
*/
ThreadListController.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: ThreadListController.url(options),
    method: 'head',
})

export default ThreadListController