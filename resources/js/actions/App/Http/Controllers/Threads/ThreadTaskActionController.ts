import { queryParams, type RouteQueryOptions, type RouteDefinition, applyUrlDefaults } from './../../../../../wayfinder'
/**
* @see \App\Http\Controllers\Threads\ThreadTaskActionController::retry
* @see app/Http/Controllers/Threads/ThreadTaskActionController.php:30
* @route '/threads/{thread}/retry'
*/
export const retry = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: retry.url(args, options),
    method: 'post',
})

retry.definition = {
    methods: ["post"],
    url: '/threads/{thread}/retry',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Threads\ThreadTaskActionController::retry
* @see app/Http/Controllers/Threads/ThreadTaskActionController.php:30
* @route '/threads/{thread}/retry'
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
* @see \App\Http\Controllers\Threads\ThreadTaskActionController::retry
* @see app/Http/Controllers/Threads/ThreadTaskActionController.php:30
* @route '/threads/{thread}/retry'
*/
retry.post = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: retry.url(args, options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Threads\ThreadTaskActionController::cancel
* @see app/Http/Controllers/Threads/ThreadTaskActionController.php:59
* @route '/threads/{thread}/cancel'
*/
export const cancel = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: cancel.url(args, options),
    method: 'post',
})

cancel.definition = {
    methods: ["post"],
    url: '/threads/{thread}/cancel',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Threads\ThreadTaskActionController::cancel
* @see app/Http/Controllers/Threads/ThreadTaskActionController.php:59
* @route '/threads/{thread}/cancel'
*/
cancel.url = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions) => {
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

    return cancel.definition.url
            .replace('{thread}', parsedArgs.thread.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Threads\ThreadTaskActionController::cancel
* @see app/Http/Controllers/Threads/ThreadTaskActionController.php:59
* @route '/threads/{thread}/cancel'
*/
cancel.post = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: cancel.url(args, options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Threads\ThreadTaskActionController::move
* @see app/Http/Controllers/Threads/ThreadTaskActionController.php:71
* @route '/threads/{thread}/move'
*/
export const move = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: move.url(args, options),
    method: 'post',
})

move.definition = {
    methods: ["post"],
    url: '/threads/{thread}/move',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Threads\ThreadTaskActionController::move
* @see app/Http/Controllers/Threads/ThreadTaskActionController.php:71
* @route '/threads/{thread}/move'
*/
move.url = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions) => {
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

    return move.definition.url
            .replace('{thread}', parsedArgs.thread.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Threads\ThreadTaskActionController::move
* @see app/Http/Controllers/Threads/ThreadTaskActionController.php:71
* @route '/threads/{thread}/move'
*/
move.post = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: move.url(args, options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Threads\ThreadTaskActionController::setUpRepositoryAndContinue
* @see app/Http/Controllers/Threads/ThreadTaskActionController.php:47
* @route '/threads/{thread}/setup-and-continue'
*/
export const setUpRepositoryAndContinue = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: setUpRepositoryAndContinue.url(args, options),
    method: 'post',
})

setUpRepositoryAndContinue.definition = {
    methods: ["post"],
    url: '/threads/{thread}/setup-and-continue',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Threads\ThreadTaskActionController::setUpRepositoryAndContinue
* @see app/Http/Controllers/Threads/ThreadTaskActionController.php:47
* @route '/threads/{thread}/setup-and-continue'
*/
setUpRepositoryAndContinue.url = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions) => {
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

    return setUpRepositoryAndContinue.definition.url
            .replace('{thread}', parsedArgs.thread.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Threads\ThreadTaskActionController::setUpRepositoryAndContinue
* @see app/Http/Controllers/Threads/ThreadTaskActionController.php:47
* @route '/threads/{thread}/setup-and-continue'
*/
setUpRepositoryAndContinue.post = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: setUpRepositoryAndContinue.url(args, options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Threads\ThreadTaskActionController::requestReview
* @see app/Http/Controllers/Threads/ThreadTaskActionController.php:85
* @route '/threads/{thread}/request-review'
*/
export const requestReview = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: requestReview.url(args, options),
    method: 'post',
})

requestReview.definition = {
    methods: ["post"],
    url: '/threads/{thread}/request-review',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Threads\ThreadTaskActionController::requestReview
* @see app/Http/Controllers/Threads/ThreadTaskActionController.php:85
* @route '/threads/{thread}/request-review'
*/
requestReview.url = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions) => {
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

    return requestReview.definition.url
            .replace('{thread}', parsedArgs.thread.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Threads\ThreadTaskActionController::requestReview
* @see app/Http/Controllers/Threads/ThreadTaskActionController.php:85
* @route '/threads/{thread}/request-review'
*/
requestReview.post = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: requestReview.url(args, options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Threads\ThreadTaskActionController::retryRender
* @see app/Http/Controllers/Threads/ThreadTaskActionController.php:105
* @route '/threads/{thread}/retry-render'
*/
export const retryRender = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: retryRender.url(args, options),
    method: 'post',
})

retryRender.definition = {
    methods: ["post"],
    url: '/threads/{thread}/retry-render',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Threads\ThreadTaskActionController::retryRender
* @see app/Http/Controllers/Threads/ThreadTaskActionController.php:105
* @route '/threads/{thread}/retry-render'
*/
retryRender.url = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions) => {
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

    return retryRender.definition.url
            .replace('{thread}', parsedArgs.thread.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Threads\ThreadTaskActionController::retryRender
* @see app/Http/Controllers/Threads/ThreadTaskActionController.php:105
* @route '/threads/{thread}/retry-render'
*/
retryRender.post = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: retryRender.url(args, options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Threads\ThreadTaskActionController::answerClarification
* @see app/Http/Controllers/Threads/ThreadTaskActionController.php:117
* @route '/threads/{thread}/clarification-answers'
*/
export const answerClarification = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: answerClarification.url(args, options),
    method: 'post',
})

answerClarification.definition = {
    methods: ["post"],
    url: '/threads/{thread}/clarification-answers',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Threads\ThreadTaskActionController::answerClarification
* @see app/Http/Controllers/Threads/ThreadTaskActionController.php:117
* @route '/threads/{thread}/clarification-answers'
*/
answerClarification.url = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions) => {
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

    return answerClarification.definition.url
            .replace('{thread}', parsedArgs.thread.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Threads\ThreadTaskActionController::answerClarification
* @see app/Http/Controllers/Threads/ThreadTaskActionController.php:117
* @route '/threads/{thread}/clarification-answers'
*/
answerClarification.post = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: answerClarification.url(args, options),
    method: 'post',
})

const ThreadTaskActionController = { retry, cancel, move, setUpRepositoryAndContinue, requestReview, retryRender, answerClarification }

export default ThreadTaskActionController