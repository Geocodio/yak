import { queryParams, type RouteQueryOptions, type RouteDefinition, applyUrlDefaults } from './../../../../../../wayfinder'
/**
* @see \App\Http\Controllers\Threads\Preview\ThreadPreviewHeartbeatController::__invoke
* @see app/Http/Controllers/Threads/Preview/ThreadPreviewHeartbeatController.php:15
* @route '/threads/{thread}/preview/heartbeat'
*/
const ThreadPreviewHeartbeatController = (args: { thread: number | { id: number } } | [thread: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: ThreadPreviewHeartbeatController.url(args, options),
    method: 'post',
})

ThreadPreviewHeartbeatController.definition = {
    methods: ["post"],
    url: '/threads/{thread}/preview/heartbeat',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Threads\Preview\ThreadPreviewHeartbeatController::__invoke
* @see app/Http/Controllers/Threads/Preview/ThreadPreviewHeartbeatController.php:15
* @route '/threads/{thread}/preview/heartbeat'
*/
ThreadPreviewHeartbeatController.url = (args: { thread: number | { id: number } } | [thread: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
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

    return ThreadPreviewHeartbeatController.definition.url
            .replace('{thread}', parsedArgs.thread.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Threads\Preview\ThreadPreviewHeartbeatController::__invoke
* @see app/Http/Controllers/Threads/Preview/ThreadPreviewHeartbeatController.php:15
* @route '/threads/{thread}/preview/heartbeat'
*/
ThreadPreviewHeartbeatController.post = (args: { thread: number | { id: number } } | [thread: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: ThreadPreviewHeartbeatController.url(args, options),
    method: 'post',
})

export default ThreadPreviewHeartbeatController