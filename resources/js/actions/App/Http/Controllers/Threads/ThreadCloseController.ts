import { queryParams, type RouteQueryOptions, type RouteDefinition, applyUrlDefaults } from './../../../../../wayfinder'
/**
* @see \App\Http\Controllers\Threads\ThreadCloseController::__invoke
* @see app/Http/Controllers/Threads/ThreadCloseController.php:13
* @route '/threads/{thread}/close'
*/
const ThreadCloseController = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: ThreadCloseController.url(args, options),
    method: 'post',
})

ThreadCloseController.definition = {
    methods: ["post"],
    url: '/threads/{thread}/close',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Threads\ThreadCloseController::__invoke
* @see app/Http/Controllers/Threads/ThreadCloseController.php:13
* @route '/threads/{thread}/close'
*/
ThreadCloseController.url = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions) => {
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

    return ThreadCloseController.definition.url
            .replace('{thread}', parsedArgs.thread.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Threads\ThreadCloseController::__invoke
* @see app/Http/Controllers/Threads/ThreadCloseController.php:13
* @route '/threads/{thread}/close'
*/
ThreadCloseController.post = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: ThreadCloseController.url(args, options),
    method: 'post',
})

export default ThreadCloseController