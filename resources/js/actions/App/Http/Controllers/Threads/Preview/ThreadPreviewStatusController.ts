import { queryParams, type RouteQueryOptions, type RouteDefinition, applyUrlDefaults } from './../../../../../../wayfinder'
/**
* @see \App\Http\Controllers\Threads\Preview\ThreadPreviewStatusController::__invoke
* @see app/Http/Controllers/Threads/Preview/ThreadPreviewStatusController.php:14
* @route '/threads/{thread}/preview/status'
*/
const ThreadPreviewStatusController = (args: { thread: number | { id: number } } | [thread: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: ThreadPreviewStatusController.url(args, options),
    method: 'get',
})

ThreadPreviewStatusController.definition = {
    methods: ["get","head"],
    url: '/threads/{thread}/preview/status',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Threads\Preview\ThreadPreviewStatusController::__invoke
* @see app/Http/Controllers/Threads/Preview/ThreadPreviewStatusController.php:14
* @route '/threads/{thread}/preview/status'
*/
ThreadPreviewStatusController.url = (args: { thread: number | { id: number } } | [thread: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { thread: args }
    }

    if (typeof args === 'object' && !Array.isArray(args) && 'id' in args) {
        args = { thread: args.id }
    }

    if (Array.isArray(args)) {
        args = {
            thread: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        thread: typeof args.thread === 'object'
        ? args.thread.id
        : args.thread,
    }

    return ThreadPreviewStatusController.definition.url
            .replace('{thread}', parsedArgs.thread.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Threads\Preview\ThreadPreviewStatusController::__invoke
* @see app/Http/Controllers/Threads/Preview/ThreadPreviewStatusController.php:14
* @route '/threads/{thread}/preview/status'
*/
ThreadPreviewStatusController.get = (args: { thread: number | { id: number } } | [thread: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: ThreadPreviewStatusController.url(args, options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Threads\Preview\ThreadPreviewStatusController::__invoke
* @see app/Http/Controllers/Threads/Preview/ThreadPreviewStatusController.php:14
* @route '/threads/{thread}/preview/status'
*/
ThreadPreviewStatusController.head = (args: { thread: number | { id: number } } | [thread: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: ThreadPreviewStatusController.url(args, options),
    method: 'head',
})

export default ThreadPreviewStatusController