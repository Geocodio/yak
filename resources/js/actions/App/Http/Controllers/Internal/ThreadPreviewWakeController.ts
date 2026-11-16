import { queryParams, type RouteQueryOptions, type RouteDefinition } from './../../../../../wayfinder'
/**
* @see \App\Http\Controllers\Internal\ThreadPreviewWakeController::__invoke
* @see app/Http/Controllers/Internal/ThreadPreviewWakeController.php:27
* @route '/internal/threads/preview/wake'
*/
const ThreadPreviewWakeController = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: ThreadPreviewWakeController.url(options),
    method: 'get',
})

ThreadPreviewWakeController.definition = {
    methods: ["get","head"],
    url: '/internal/threads/preview/wake',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Internal\ThreadPreviewWakeController::__invoke
* @see app/Http/Controllers/Internal/ThreadPreviewWakeController.php:27
* @route '/internal/threads/preview/wake'
*/
ThreadPreviewWakeController.url = (options?: RouteQueryOptions) => {
    return ThreadPreviewWakeController.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Internal\ThreadPreviewWakeController::__invoke
* @see app/Http/Controllers/Internal/ThreadPreviewWakeController.php:27
* @route '/internal/threads/preview/wake'
*/
ThreadPreviewWakeController.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: ThreadPreviewWakeController.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Internal\ThreadPreviewWakeController::__invoke
* @see app/Http/Controllers/Internal/ThreadPreviewWakeController.php:27
* @route '/internal/threads/preview/wake'
*/
ThreadPreviewWakeController.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: ThreadPreviewWakeController.url(options),
    method: 'head',
})

export default ThreadPreviewWakeController