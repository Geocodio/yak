import { queryParams, type RouteQueryOptions, type RouteDefinition, applyUrlDefaults } from './../../../../../wayfinder'
/**
* @see \App\Http\Controllers\Threads\ThreadStarController::__invoke
* @see app/Http/Controllers/Threads/ThreadStarController.php:17
* @route '/threads/{thread}/star'
*/
const ThreadStarController = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: ThreadStarController.url(args, options),
    method: 'post',
})

ThreadStarController.definition = {
    methods: ["post"],
    url: '/threads/{thread}/star',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Threads\ThreadStarController::__invoke
* @see app/Http/Controllers/Threads/ThreadStarController.php:17
* @route '/threads/{thread}/star'
*/
ThreadStarController.url = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions) => {
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

    return ThreadStarController.definition.url
            .replace('{thread}', parsedArgs.thread.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Threads\ThreadStarController::__invoke
* @see app/Http/Controllers/Threads/ThreadStarController.php:17
* @route '/threads/{thread}/star'
*/
ThreadStarController.post = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: ThreadStarController.url(args, options),
    method: 'post',
})

export default ThreadStarController