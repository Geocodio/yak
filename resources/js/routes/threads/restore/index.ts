import { queryParams, type RouteQueryOptions, type RouteDefinition, applyUrlDefaults } from './../../../wayfinder'
/**
* @see \App\Http\Controllers\Threads\RestoreRetryController::__invoke
* @see app/Http/Controllers/Threads/RestoreRetryController.php:16
* @route '/threads/{thread}/restore/retry'
*/
export const retry = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: retry.url(args, options),
    method: 'post',
})

retry.definition = {
    methods: ["post"],
    url: '/threads/{thread}/restore/retry',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Threads\RestoreRetryController::__invoke
* @see app/Http/Controllers/Threads/RestoreRetryController.php:16
* @route '/threads/{thread}/restore/retry'
*/
retry.url = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions) => {
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

    return retry.definition.url
            .replace('{thread}', parsedArgs.thread.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Threads\RestoreRetryController::__invoke
* @see app/Http/Controllers/Threads/RestoreRetryController.php:16
* @route '/threads/{thread}/restore/retry'
*/
retry.post = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: retry.url(args, options),
    method: 'post',
})

const restore = {
    retry: Object.assign(retry, retry),
}

export default restore