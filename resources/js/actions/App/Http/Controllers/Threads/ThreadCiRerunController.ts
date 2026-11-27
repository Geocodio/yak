import { queryParams, type RouteQueryOptions, type RouteDefinition, applyUrlDefaults } from './../../../../../wayfinder'
/**
* @see \App\Http\Controllers\Threads\ThreadCiRerunController::__invoke
* @see app/Http/Controllers/Threads/ThreadCiRerunController.php:20
* @route '/threads/{thread}/ci/rerun'
*/
const ThreadCiRerunController = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: ThreadCiRerunController.url(args, options),
    method: 'post',
})

ThreadCiRerunController.definition = {
    methods: ["post"],
    url: '/threads/{thread}/ci/rerun',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Threads\ThreadCiRerunController::__invoke
* @see app/Http/Controllers/Threads/ThreadCiRerunController.php:20
* @route '/threads/{thread}/ci/rerun'
*/
ThreadCiRerunController.url = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions) => {
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

    return ThreadCiRerunController.definition.url
            .replace('{thread}', parsedArgs.thread.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Threads\ThreadCiRerunController::__invoke
* @see app/Http/Controllers/Threads/ThreadCiRerunController.php:20
* @route '/threads/{thread}/ci/rerun'
*/
ThreadCiRerunController.post = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: ThreadCiRerunController.url(args, options),
    method: 'post',
})

export default ThreadCiRerunController