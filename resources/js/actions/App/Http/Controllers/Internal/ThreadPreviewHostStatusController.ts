import { queryParams, type RouteQueryOptions, type RouteDefinition } from './../../../../../wayfinder'
/**
* @see \App\Http\Controllers\Internal\ThreadPreviewHostStatusController::__invoke
* @see app/Http/Controllers/Internal/ThreadPreviewHostStatusController.php:14
* @route '/internal/threads/preview/status'
*/
const ThreadPreviewHostStatusController = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: ThreadPreviewHostStatusController.url(options),
    method: 'get',
})

ThreadPreviewHostStatusController.definition = {
    methods: ["get","head"],
    url: '/internal/threads/preview/status',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Internal\ThreadPreviewHostStatusController::__invoke
* @see app/Http/Controllers/Internal/ThreadPreviewHostStatusController.php:14
* @route '/internal/threads/preview/status'
*/
ThreadPreviewHostStatusController.url = (options?: RouteQueryOptions) => {
    return ThreadPreviewHostStatusController.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Internal\ThreadPreviewHostStatusController::__invoke
* @see app/Http/Controllers/Internal/ThreadPreviewHostStatusController.php:14
* @route '/internal/threads/preview/status'
*/
ThreadPreviewHostStatusController.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: ThreadPreviewHostStatusController.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Internal\ThreadPreviewHostStatusController::__invoke
* @see app/Http/Controllers/Internal/ThreadPreviewHostStatusController.php:14
* @route '/internal/threads/preview/status'
*/
ThreadPreviewHostStatusController.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: ThreadPreviewHostStatusController.url(options),
    method: 'head',
})

export default ThreadPreviewHostStatusController