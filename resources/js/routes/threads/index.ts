import { queryParams, type RouteQueryOptions, type RouteDefinition, applyUrlDefaults } from './../../wayfinder'
import messages from './messages'
import repositoryAnswer from './repository-answer'
import clarificationAnswers from './clarification-answers'
import coOwner from './co-owner'
import queuedMessages from './queued-messages'
import restore from './restore'
import sharedFiles from './shared-files'
import diff from './diff'
import runs from './runs'
import tasks from './tasks'
import environment from './environment'
import pullRequest from './pull-request'
import events from './events'
import share from './share'
import teleportRequest from './teleport-request'
import stack from './stack'
import preview from './preview'
import files from './files'
import terminalSessions from './terminal-sessions'
import repositories from './repositories'
import pullRequests from './pull-requests'
/**
* @see \App\Http\Controllers\Threads\ThreadMessageController::stop
* @see app/Http/Controllers/Threads/ThreadMessageController.php:82
* @route '/threads/{thread}/stop'
*/
export const stop = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: stop.url(args, options),
    method: 'post',
})

stop.definition = {
    methods: ["post"],
    url: '/threads/{thread}/stop',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Threads\ThreadMessageController::stop
* @see app/Http/Controllers/Threads/ThreadMessageController.php:82
* @route '/threads/{thread}/stop'
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
* @see \App\Http\Controllers\Threads\ThreadMessageController::stop
* @see app/Http/Controllers/Threads/ThreadMessageController.php:82
* @route '/threads/{thread}/stop'
*/
stop.post = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: stop.url(args, options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Threads\ThreadTaskActionController::retry
* @see app/Http/Controllers/Threads/ThreadTaskActionController.php:28
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
* @see app/Http/Controllers/Threads/ThreadTaskActionController.php:28
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
* @see app/Http/Controllers/Threads/ThreadTaskActionController.php:28
* @route '/threads/{thread}/retry'
*/
retry.post = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: retry.url(args, options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Threads\ThreadTaskActionController::cancel
* @see app/Http/Controllers/Threads/ThreadTaskActionController.php:38
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
* @see app/Http/Controllers/Threads/ThreadTaskActionController.php:38
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
* @see app/Http/Controllers/Threads/ThreadTaskActionController.php:38
* @route '/threads/{thread}/cancel'
*/
cancel.post = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: cancel.url(args, options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Threads\ThreadTaskActionController::move
* @see app/Http/Controllers/Threads/ThreadTaskActionController.php:43
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
* @see app/Http/Controllers/Threads/ThreadTaskActionController.php:43
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
* @see app/Http/Controllers/Threads/ThreadTaskActionController.php:43
* @route '/threads/{thread}/move'
*/
move.post = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: move.url(args, options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Threads\ThreadTaskActionController::setupAndContinue
* @see app/Http/Controllers/Threads/ThreadTaskActionController.php:33
* @route '/threads/{thread}/setup-and-continue'
*/
export const setupAndContinue = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: setupAndContinue.url(args, options),
    method: 'post',
})

setupAndContinue.definition = {
    methods: ["post"],
    url: '/threads/{thread}/setup-and-continue',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Threads\ThreadTaskActionController::setupAndContinue
* @see app/Http/Controllers/Threads/ThreadTaskActionController.php:33
* @route '/threads/{thread}/setup-and-continue'
*/
setupAndContinue.url = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions) => {
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

    return setupAndContinue.definition.url
            .replace('{thread}', parsedArgs.thread.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Threads\ThreadTaskActionController::setupAndContinue
* @see app/Http/Controllers/Threads/ThreadTaskActionController.php:33
* @route '/threads/{thread}/setup-and-continue'
*/
setupAndContinue.post = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: setupAndContinue.url(args, options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Threads\ThreadTaskActionController::requestReview
* @see app/Http/Controllers/Threads/ThreadTaskActionController.php:60
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
* @see app/Http/Controllers/Threads/ThreadTaskActionController.php:60
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
* @see app/Http/Controllers/Threads/ThreadTaskActionController.php:60
* @route '/threads/{thread}/request-review'
*/
requestReview.post = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: requestReview.url(args, options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Threads\ThreadTaskActionController::retryRender
* @see app/Http/Controllers/Threads/ThreadTaskActionController.php:71
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
* @see app/Http/Controllers/Threads/ThreadTaskActionController.php:71
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
* @see app/Http/Controllers/Threads/ThreadTaskActionController.php:71
* @route '/threads/{thread}/retry-render'
*/
retryRender.post = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: retryRender.url(args, options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Threads\ContinueTurnController::__invoke
* @see app/Http/Controllers/Threads/ContinueTurnController.php:16
* @route '/threads/{thread}/continue'
*/
export const continueMethod = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: continueMethod.url(args, options),
    method: 'post',
})

continueMethod.definition = {
    methods: ["post"],
    url: '/threads/{thread}/continue',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Threads\ContinueTurnController::__invoke
* @see app/Http/Controllers/Threads/ContinueTurnController.php:16
* @route '/threads/{thread}/continue'
*/
continueMethod.url = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions) => {
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

    return continueMethod.definition.url
            .replace('{thread}', parsedArgs.thread.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Threads\ContinueTurnController::__invoke
* @see app/Http/Controllers/Threads/ContinueTurnController.php:16
* @route '/threads/{thread}/continue'
*/
continueMethod.post = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: continueMethod.url(args, options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Threads\ThreadLogController::__invoke
* @see app/Http/Controllers/Threads/ThreadLogController.php:19
* @route '/threads/log'
*/
export const log = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: log.url(options),
    method: 'get',
})

log.definition = {
    methods: ["get","head"],
    url: '/threads/log',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Threads\ThreadLogController::__invoke
* @see app/Http/Controllers/Threads/ThreadLogController.php:19
* @route '/threads/log'
*/
log.url = (options?: RouteQueryOptions) => {
    return log.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Threads\ThreadLogController::__invoke
* @see app/Http/Controllers/Threads/ThreadLogController.php:19
* @route '/threads/log'
*/
log.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: log.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Threads\ThreadLogController::__invoke
* @see app/Http/Controllers/Threads/ThreadLogController.php:19
* @route '/threads/log'
*/
log.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: log.url(options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\Threads\NewThreadController::__invoke
* @see app/Http/Controllers/Threads/NewThreadController.php:14
* @route '/threads/new'
*/
export const newMethod = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: newMethod.url(options),
    method: 'get',
})

newMethod.definition = {
    methods: ["get","head"],
    url: '/threads/new',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Threads\NewThreadController::__invoke
* @see app/Http/Controllers/Threads/NewThreadController.php:14
* @route '/threads/new'
*/
newMethod.url = (options?: RouteQueryOptions) => {
    return newMethod.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Threads\NewThreadController::__invoke
* @see app/Http/Controllers/Threads/NewThreadController.php:14
* @route '/threads/new'
*/
newMethod.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: newMethod.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Threads\NewThreadController::__invoke
* @see app/Http/Controllers/Threads/NewThreadController.php:14
* @route '/threads/new'
*/
newMethod.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: newMethod.url(options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\Threads\StoreThreadController::__invoke
* @see app/Http/Controllers/Threads/StoreThreadController.php:17
* @route '/threads'
*/
export const store = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: store.url(options),
    method: 'post',
})

store.definition = {
    methods: ["post"],
    url: '/threads',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Threads\StoreThreadController::__invoke
* @see app/Http/Controllers/Threads/StoreThreadController.php:17
* @route '/threads'
*/
store.url = (options?: RouteQueryOptions) => {
    return store.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Threads\StoreThreadController::__invoke
* @see app/Http/Controllers/Threads/StoreThreadController.php:17
* @route '/threads'
*/
store.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: store.url(options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Threads\ThreadController::show
* @see app/Http/Controllers/Threads/ThreadController.php:21
* @route '/threads/{thread}'
*/
export const show = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: show.url(args, options),
    method: 'get',
})

show.definition = {
    methods: ["get","head"],
    url: '/threads/{thread}',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Threads\ThreadController::show
* @see app/Http/Controllers/Threads/ThreadController.php:21
* @route '/threads/{thread}'
*/
show.url = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions) => {
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

    return show.definition.url
            .replace('{thread}', parsedArgs.thread.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Threads\ThreadController::show
* @see app/Http/Controllers/Threads/ThreadController.php:21
* @route '/threads/{thread}'
*/
show.get = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: show.url(args, options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Threads\ThreadController::show
* @see app/Http/Controllers/Threads/ThreadController.php:21
* @route '/threads/{thread}'
*/
show.head = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: show.url(args, options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\Threads\ThreadListController::__invoke
* @see app/Http/Controllers/Threads/ThreadListController.php:14
* @route '/threads/list'
*/
export const list = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: list.url(options),
    method: 'get',
})

list.definition = {
    methods: ["get","head"],
    url: '/threads/list',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Threads\ThreadListController::__invoke
* @see app/Http/Controllers/Threads/ThreadListController.php:14
* @route '/threads/list'
*/
list.url = (options?: RouteQueryOptions) => {
    return list.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Threads\ThreadListController::__invoke
* @see app/Http/Controllers/Threads/ThreadListController.php:14
* @route '/threads/list'
*/
list.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: list.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Threads\ThreadListController::__invoke
* @see app/Http/Controllers/Threads/ThreadListController.php:14
* @route '/threads/list'
*/
list.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: list.url(options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\Threads\ThreadSeenController::__invoke
* @see app/Http/Controllers/Threads/ThreadSeenController.php:14
* @route '/threads/{thread}/seen'
*/
export const seen = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: seen.url(args, options),
    method: 'post',
})

seen.definition = {
    methods: ["post"],
    url: '/threads/{thread}/seen',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Threads\ThreadSeenController::__invoke
* @see app/Http/Controllers/Threads/ThreadSeenController.php:14
* @route '/threads/{thread}/seen'
*/
seen.url = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions) => {
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

    return seen.definition.url
            .replace('{thread}', parsedArgs.thread.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Threads\ThreadSeenController::__invoke
* @see app/Http/Controllers/Threads/ThreadSeenController.php:14
* @route '/threads/{thread}/seen'
*/
seen.post = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: seen.url(args, options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Threads\ThreadStarController::__invoke
* @see app/Http/Controllers/Threads/ThreadStarController.php:17
* @route '/threads/{thread}/star'
*/
export const star = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: star.url(args, options),
    method: 'post',
})

star.definition = {
    methods: ["post"],
    url: '/threads/{thread}/star',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Threads\ThreadStarController::__invoke
* @see app/Http/Controllers/Threads/ThreadStarController.php:17
* @route '/threads/{thread}/star'
*/
star.url = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions) => {
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

    return star.definition.url
            .replace('{thread}', parsedArgs.thread.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Threads\ThreadStarController::__invoke
* @see app/Http/Controllers/Threads/ThreadStarController.php:17
* @route '/threads/{thread}/star'
*/
star.post = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: star.url(args, options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Threads\ThreadCloseController::__invoke
* @see app/Http/Controllers/Threads/ThreadCloseController.php:13
* @route '/threads/{thread}/close'
*/
export const close = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: close.url(args, options),
    method: 'post',
})

close.definition = {
    methods: ["post"],
    url: '/threads/{thread}/close',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Threads\ThreadCloseController::__invoke
* @see app/Http/Controllers/Threads/ThreadCloseController.php:13
* @route '/threads/{thread}/close'
*/
close.url = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions) => {
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

    return close.definition.url
            .replace('{thread}', parsedArgs.thread.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Threads\ThreadCloseController::__invoke
* @see app/Http/Controllers/Threads/ThreadCloseController.php:13
* @route '/threads/{thread}/close'
*/
close.post = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: close.url(args, options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Threads\ThreadDestroyController::__invoke
* @see app/Http/Controllers/Threads/ThreadDestroyController.php:14
* @route '/threads/{thread}'
*/
export const destroy = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: destroy.url(args, options),
    method: 'delete',
})

destroy.definition = {
    methods: ["delete"],
    url: '/threads/{thread}',
} satisfies RouteDefinition<["delete"]>

/**
* @see \App\Http\Controllers\Threads\ThreadDestroyController::__invoke
* @see app/Http/Controllers/Threads/ThreadDestroyController.php:14
* @route '/threads/{thread}'
*/
destroy.url = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions) => {
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

    return destroy.definition.url
            .replace('{thread}', parsedArgs.thread.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Threads\ThreadDestroyController::__invoke
* @see app/Http/Controllers/Threads/ThreadDestroyController.php:14
* @route '/threads/{thread}'
*/
destroy.delete = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: destroy.url(args, options),
    method: 'delete',
})

/**
* @see \App\Http\Controllers\Threads\LocalTurnsController::__invoke
* @see app/Http/Controllers/Threads/LocalTurnsController.php:14
* @route '/threads/{thread}/local-turns'
*/
export const localTurns = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: localTurns.url(args, options),
    method: 'get',
})

localTurns.definition = {
    methods: ["get","head"],
    url: '/threads/{thread}/local-turns',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Threads\LocalTurnsController::__invoke
* @see app/Http/Controllers/Threads/LocalTurnsController.php:14
* @route '/threads/{thread}/local-turns'
*/
localTurns.url = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions) => {
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

    return localTurns.definition.url
            .replace('{thread}', parsedArgs.thread.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Threads\LocalTurnsController::__invoke
* @see app/Http/Controllers/Threads/LocalTurnsController.php:14
* @route '/threads/{thread}/local-turns'
*/
localTurns.get = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: localTurns.url(args, options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Threads\LocalTurnsController::__invoke
* @see app/Http/Controllers/Threads/LocalTurnsController.php:14
* @route '/threads/{thread}/local-turns'
*/
localTurns.head = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: localTurns.url(args, options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\Threads\RepositoryAccessController::__invoke
* @see app/Http/Controllers/Threads/RepositoryAccessController.php:15
* @route '/threads/new/repository-access'
*/
export const repositoryAccess = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: repositoryAccess.url(options),
    method: 'get',
})

repositoryAccess.definition = {
    methods: ["get","head"],
    url: '/threads/new/repository-access',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Threads\RepositoryAccessController::__invoke
* @see app/Http/Controllers/Threads/RepositoryAccessController.php:15
* @route '/threads/new/repository-access'
*/
repositoryAccess.url = (options?: RouteQueryOptions) => {
    return repositoryAccess.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Threads\RepositoryAccessController::__invoke
* @see app/Http/Controllers/Threads/RepositoryAccessController.php:15
* @route '/threads/new/repository-access'
*/
repositoryAccess.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: repositoryAccess.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Threads\RepositoryAccessController::__invoke
* @see app/Http/Controllers/Threads/RepositoryAccessController.php:15
* @route '/threads/new/repository-access'
*/
repositoryAccess.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: repositoryAccess.url(options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\Threads\RepositorySetupStatusController::__invoke
* @see app/Http/Controllers/Threads/RepositorySetupStatusController.php:16
* @route '/threads/new/repository-setup'
*/
export const repositorySetup = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: repositorySetup.url(options),
    method: 'get',
})

repositorySetup.definition = {
    methods: ["get","head"],
    url: '/threads/new/repository-setup',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Threads\RepositorySetupStatusController::__invoke
* @see app/Http/Controllers/Threads/RepositorySetupStatusController.php:16
* @route '/threads/new/repository-setup'
*/
repositorySetup.url = (options?: RouteQueryOptions) => {
    return repositorySetup.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Threads\RepositorySetupStatusController::__invoke
* @see app/Http/Controllers/Threads/RepositorySetupStatusController.php:16
* @route '/threads/new/repository-setup'
*/
repositorySetup.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: repositorySetup.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Threads\RepositorySetupStatusController::__invoke
* @see app/Http/Controllers/Threads/RepositorySetupStatusController.php:16
* @route '/threads/new/repository-setup'
*/
repositorySetup.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: repositorySetup.url(options),
    method: 'head',
})

const threads = {
    messages: Object.assign(messages, messages),
    stop: Object.assign(stop, stop),
    repositoryAnswer: Object.assign(repositoryAnswer, repositoryAnswer),
    retry: Object.assign(retry, retry),
    cancel: Object.assign(cancel, cancel),
    move: Object.assign(move, move),
    setupAndContinue: Object.assign(setupAndContinue, setupAndContinue),
    requestReview: Object.assign(requestReview, requestReview),
    retryRender: Object.assign(retryRender, retryRender),
    clarificationAnswers: Object.assign(clarificationAnswers, clarificationAnswers),
    coOwner: Object.assign(coOwner, coOwner),
    queuedMessages: Object.assign(queuedMessages, queuedMessages),
    continue: Object.assign(continueMethod, continueMethod),
    restore: Object.assign(restore, restore),
    log: Object.assign(log, log),
    new: Object.assign(newMethod, newMethod),
    store: Object.assign(store, store),
    show: Object.assign(show, show),
    list: Object.assign(list, list),
    seen: Object.assign(seen, seen),
    star: Object.assign(star, star),
    close: Object.assign(close, close),
    destroy: Object.assign(destroy, destroy),
    sharedFiles: Object.assign(sharedFiles, sharedFiles),
    diff: Object.assign(diff, diff),
    runs: Object.assign(runs, runs),
    tasks: Object.assign(tasks, tasks),
    localTurns: Object.assign(localTurns, localTurns),
    environment: Object.assign(environment, environment),
    pullRequest: Object.assign(pullRequest, pullRequest),
    events: Object.assign(events, events),
    share: Object.assign(share, share),
    teleportRequest: Object.assign(teleportRequest, teleportRequest),
    stack: Object.assign(stack, stack),
    preview: Object.assign(preview, preview),
    files: Object.assign(files, files),
    terminalSessions: Object.assign(terminalSessions, terminalSessions),
    repositoryAccess: Object.assign(repositoryAccess, repositoryAccess),
    repositorySetup: Object.assign(repositorySetup, repositorySetup),
    repositories: Object.assign(repositories, repositories),
    pullRequests: Object.assign(pullRequests, pullRequests),
}

export default threads