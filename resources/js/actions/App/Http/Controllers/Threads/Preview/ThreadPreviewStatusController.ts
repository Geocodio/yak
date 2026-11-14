import { queryParams, type RouteQueryOptions, type RouteDefinition, applyUrlDefaults } from './../../../../../../wayfinder'
/**
* @see \App\Http\Controllers\Threads\Preview\ThreadPreviewStatusController::__invoke
* @see Users/codemonkey/projects/yak/.worktrees/next-gen/app/Http/Controllers/Threads/Preview/ThreadPreviewStatusController.php:14
* @route '/threads/{thread}/preview/status'
*/
const ThreadPreviewStatusController = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: ThreadPreviewStatusController.url(args, options),
    method: 'get',
})

ThreadPreviewStatusController.definition = {
    methods: ["get","head"],
    url: '/threads/{thread}/preview/status',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Threads\Preview\ThreadPreviewStatusController::__invoke
* @see Users/codemonkey/projects/yak/.worktrees/next-gen/app/Http/Controllers/Threads/Preview/ThreadPreviewStatusController.php:14
* @route '/threads/{thread}/preview/status'
*/
ThreadPreviewStatusController.url = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { thread: args }
    }

    if (typeof args === 'object' && !Array.isArray(args) && 'public_id' in args) {
        args = { thread: args.public_id }
    }

    if (Array.isArray(args)) {
        args = {
            thread: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        thread: typeof args.thread === 'object'
        ? args.thread.public_id
        : args.thread,
    }

    return ThreadPreviewStatusController.definition.url
            .replace('{thread}', parsedArgs.thread.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Threads\Preview\ThreadPreviewStatusController::__invoke
* @see Users/codemonkey/projects/yak/.worktrees/next-gen/app/Http/Controllers/Threads/Preview/ThreadPreviewStatusController.php:14
* @route '/threads/{thread}/preview/status'
*/
ThreadPreviewStatusController.get = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: ThreadPreviewStatusController.url(args, options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Threads\Preview\ThreadPreviewStatusController::__invoke
* @see Users/codemonkey/projects/yak/.worktrees/next-gen/app/Http/Controllers/Threads/Preview/ThreadPreviewStatusController.php:14
* @route '/threads/{thread}/preview/status'
*/
ThreadPreviewStatusController.head = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: ThreadPreviewStatusController.url(args, options),
    method: 'head',
})

export default ThreadPreviewStatusController