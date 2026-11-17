import { queryParams, type RouteQueryOptions, type RouteDefinition } from './../../../../../wayfinder'
/**
* @see \App\Http\Controllers\Threads\ThreadLogController::__invoke
* @see app/Http/Controllers/Threads/ThreadLogController.php:19
* @route '/threads/log'
*/
const ThreadLogController = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: ThreadLogController.url(options),
    method: 'get',
})

ThreadLogController.definition = {
    methods: ["get","head"],
    url: '/threads/log',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Threads\ThreadLogController::__invoke
* @see app/Http/Controllers/Threads/ThreadLogController.php:19
* @route '/threads/log'
*/
ThreadLogController.url = (options?: RouteQueryOptions) => {
    return ThreadLogController.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Threads\ThreadLogController::__invoke
* @see app/Http/Controllers/Threads/ThreadLogController.php:19
* @route '/threads/log'
*/
ThreadLogController.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: ThreadLogController.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Threads\ThreadLogController::__invoke
* @see app/Http/Controllers/Threads/ThreadLogController.php:19
* @route '/threads/log'
*/
ThreadLogController.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: ThreadLogController.url(options),
    method: 'head',
})

export default ThreadLogController