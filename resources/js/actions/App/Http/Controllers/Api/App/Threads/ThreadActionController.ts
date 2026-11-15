import { queryParams, type RouteQueryOptions, type RouteDefinition, applyUrlDefaults } from './../../../../../../../wayfinder'
/**
* @see \App\Http\Controllers\Api\App\Threads\ThreadActionController::stop
* @see app/Http/Controllers/Api/App/Threads/ThreadActionController.php:14
* @route '/api/app/v1/threads/{thread}/stop'
*/
export const stop = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: stop.url(args, options),
    method: 'post',
})

stop.definition = {
    methods: ["post"],
    url: '/api/app/v1/threads/{thread}/stop',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Api\App\Threads\ThreadActionController::stop
* @see app/Http/Controllers/Api/App/Threads/ThreadActionController.php:14
* @route '/api/app/v1/threads/{thread}/stop'
*/
stop.url = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions) => {
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

    return stop.definition.url
            .replace('{thread}', parsedArgs.thread.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Api\App\Threads\ThreadActionController::stop
* @see app/Http/Controllers/Api/App/Threads/ThreadActionController.php:14
* @route '/api/app/v1/threads/{thread}/stop'
*/
stop.post = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: stop.url(args, options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Api\App\Threads\ThreadActionController::continueTurn
* @see app/Http/Controllers/Api/App/Threads/ThreadActionController.php:21
* @route '/api/app/v1/threads/{thread}/continue'
*/
export const continueTurn = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: continueTurn.url(args, options),
    method: 'post',
})

continueTurn.definition = {
    methods: ["post"],
    url: '/api/app/v1/threads/{thread}/continue',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Api\App\Threads\ThreadActionController::continueTurn
* @see app/Http/Controllers/Api/App/Threads/ThreadActionController.php:21
* @route '/api/app/v1/threads/{thread}/continue'
*/
continueTurn.url = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions) => {
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

    return continueTurn.definition.url
            .replace('{thread}', parsedArgs.thread.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Api\App\Threads\ThreadActionController::continueTurn
* @see app/Http/Controllers/Api/App/Threads/ThreadActionController.php:21
* @route '/api/app/v1/threads/{thread}/continue'
*/
continueTurn.post = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: continueTurn.url(args, options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Api\App\Threads\ThreadActionController::retryRestore
* @see app/Http/Controllers/Api/App/Threads/ThreadActionController.php:28
* @route '/api/app/v1/threads/{thread}/restore/retry'
*/
export const retryRestore = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: retryRestore.url(args, options),
    method: 'post',
})

retryRestore.definition = {
    methods: ["post"],
    url: '/api/app/v1/threads/{thread}/restore/retry',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Api\App\Threads\ThreadActionController::retryRestore
* @see app/Http/Controllers/Api/App/Threads/ThreadActionController.php:28
* @route '/api/app/v1/threads/{thread}/restore/retry'
*/
retryRestore.url = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions) => {
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

    return retryRestore.definition.url
            .replace('{thread}', parsedArgs.thread.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Api\App\Threads\ThreadActionController::retryRestore
* @see app/Http/Controllers/Api/App/Threads/ThreadActionController.php:28
* @route '/api/app/v1/threads/{thread}/restore/retry'
*/
retryRestore.post = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: retryRestore.url(args, options),
    method: 'post',
})

const ThreadActionController = { stop, continueTurn, retryRestore }

export default ThreadActionController