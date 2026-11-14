import { queryParams, type RouteQueryOptions, type RouteDefinition, applyUrlDefaults } from './../../../../../../wayfinder'
/**
* @see \App\Http\Controllers\Threads\Preview\ThreadPreviewHeartbeatController::__invoke
* @see Users/codemonkey/projects/yak/.worktrees/next-gen/app/Http/Controllers/Threads/Preview/ThreadPreviewHeartbeatController.php:15
* @route '/threads/{thread}/preview/heartbeat'
*/
const ThreadPreviewHeartbeatController = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: ThreadPreviewHeartbeatController.url(args, options),
    method: 'post',
})

ThreadPreviewHeartbeatController.definition = {
    methods: ["post"],
    url: '/threads/{thread}/preview/heartbeat',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Threads\Preview\ThreadPreviewHeartbeatController::__invoke
* @see Users/codemonkey/projects/yak/.worktrees/next-gen/app/Http/Controllers/Threads/Preview/ThreadPreviewHeartbeatController.php:15
* @route '/threads/{thread}/preview/heartbeat'
*/
ThreadPreviewHeartbeatController.url = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions) => {
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

    return ThreadPreviewHeartbeatController.definition.url
            .replace('{thread}', parsedArgs.thread.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Threads\Preview\ThreadPreviewHeartbeatController::__invoke
* @see Users/codemonkey/projects/yak/.worktrees/next-gen/app/Http/Controllers/Threads/Preview/ThreadPreviewHeartbeatController.php:15
* @route '/threads/{thread}/preview/heartbeat'
*/
ThreadPreviewHeartbeatController.post = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: ThreadPreviewHeartbeatController.url(args, options),
    method: 'post',
})

export default ThreadPreviewHeartbeatController