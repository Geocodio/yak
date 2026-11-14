import { queryParams, type RouteQueryOptions, type RouteDefinition, applyUrlDefaults } from './../../../../../../wayfinder'
/**
* @see \App\Http\Controllers\Threads\Preview\ThreadPreviewRestoreController::__invoke
* @see Users/codemonkey/projects/yak/.worktrees/next-gen/app/Http/Controllers/Threads/Preview/ThreadPreviewRestoreController.php:16
* @route '/threads/{thread}/preview/restore'
*/
const ThreadPreviewRestoreController = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: ThreadPreviewRestoreController.url(args, options),
    method: 'post',
})

ThreadPreviewRestoreController.definition = {
    methods: ["post"],
    url: '/threads/{thread}/preview/restore',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Threads\Preview\ThreadPreviewRestoreController::__invoke
* @see Users/codemonkey/projects/yak/.worktrees/next-gen/app/Http/Controllers/Threads/Preview/ThreadPreviewRestoreController.php:16
* @route '/threads/{thread}/preview/restore'
*/
ThreadPreviewRestoreController.url = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions) => {
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

    return ThreadPreviewRestoreController.definition.url
            .replace('{thread}', parsedArgs.thread.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Threads\Preview\ThreadPreviewRestoreController::__invoke
* @see Users/codemonkey/projects/yak/.worktrees/next-gen/app/Http/Controllers/Threads/Preview/ThreadPreviewRestoreController.php:16
* @route '/threads/{thread}/preview/restore'
*/
ThreadPreviewRestoreController.post = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: ThreadPreviewRestoreController.url(args, options),
    method: 'post',
})

export default ThreadPreviewRestoreController