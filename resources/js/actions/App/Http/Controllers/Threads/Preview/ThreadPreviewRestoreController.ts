import { queryParams, type RouteQueryOptions, type RouteDefinition, applyUrlDefaults } from './../../../../../../wayfinder'
/**
* @see \App\Http\Controllers\Threads\Preview\ThreadPreviewRestoreController::__invoke
* @see app/Http/Controllers/Threads/Preview/ThreadPreviewRestoreController.php:16
* @route '/threads/{thread}/preview/restore'
*/
const ThreadPreviewRestoreController = (args: { thread: number | { id: number } } | [thread: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: ThreadPreviewRestoreController.url(args, options),
    method: 'post',
})

ThreadPreviewRestoreController.definition = {
    methods: ["post"],
    url: '/threads/{thread}/preview/restore',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Threads\Preview\ThreadPreviewRestoreController::__invoke
* @see app/Http/Controllers/Threads/Preview/ThreadPreviewRestoreController.php:16
* @route '/threads/{thread}/preview/restore'
*/
ThreadPreviewRestoreController.url = (args: { thread: number | { id: number } } | [thread: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
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

    return ThreadPreviewRestoreController.definition.url
            .replace('{thread}', parsedArgs.thread.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Threads\Preview\ThreadPreviewRestoreController::__invoke
* @see app/Http/Controllers/Threads/Preview/ThreadPreviewRestoreController.php:16
* @route '/threads/{thread}/preview/restore'
*/
ThreadPreviewRestoreController.post = (args: { thread: number | { id: number } } | [thread: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: ThreadPreviewRestoreController.url(args, options),
    method: 'post',
})

export default ThreadPreviewRestoreController