import { queryParams, type RouteQueryOptions, type RouteDefinition, applyUrlDefaults } from './../../../../../../../wayfinder'
/**
* @see \App\Http\Controllers\Api\App\Threads\ThreadStepLimitController::__invoke
* @see app/Http/Controllers/Api/App/Threads/ThreadStepLimitController.php:18
* @route '/api/app/v1/threads/{thread}/step-limit'
*/
const ThreadStepLimitController = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: ThreadStepLimitController.url(args, options),
    method: 'post',
})

ThreadStepLimitController.definition = {
    methods: ["post"],
    url: '/api/app/v1/threads/{thread}/step-limit',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Api\App\Threads\ThreadStepLimitController::__invoke
* @see app/Http/Controllers/Api/App/Threads/ThreadStepLimitController.php:18
* @route '/api/app/v1/threads/{thread}/step-limit'
*/
ThreadStepLimitController.url = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions) => {
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

    return ThreadStepLimitController.definition.url
            .replace('{thread}', parsedArgs.thread.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Api\App\Threads\ThreadStepLimitController::__invoke
* @see app/Http/Controllers/Api/App/Threads/ThreadStepLimitController.php:18
* @route '/api/app/v1/threads/{thread}/step-limit'
*/
ThreadStepLimitController.post = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: ThreadStepLimitController.url(args, options),
    method: 'post',
})

export default ThreadStepLimitController