import { queryParams, type RouteQueryOptions, type RouteDefinition, applyUrlDefaults } from './../../../wayfinder'
import messages from './messages'
import clarificationAnswers from './clarification-answers'
import share from './share'
import restore from './restore'
import queuedMessages from './queued-messages'
import events from './events'
import diff from './diff'
import pullRequest from './pull-request'
import pullRequests from './pull-requests'
import stepLimit from './step-limit'
import agents from './agents'
import stack from './stack'
import environment from './environment'
import repositories from './repositories'
import preview from './preview'
import terminalSessions from './terminal-sessions'
import files from './files'
import coOwner from './co-owner'
/**
* @see \App\Http\Controllers\Api\App\Threads\ThreadListController::__invoke
* @see app/Http/Controllers/Api/App/Threads/ThreadListController.php:12
* @route '/api/app/v1/threads'
*/
export const index = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: index.url(options),
    method: 'get',
})

index.definition = {
    methods: ["get","head"],
    url: '/api/app/v1/threads',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Api\App\Threads\ThreadListController::__invoke
* @see app/Http/Controllers/Api/App/Threads/ThreadListController.php:12
* @route '/api/app/v1/threads'
*/
index.url = (options?: RouteQueryOptions) => {
    return index.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Api\App\Threads\ThreadListController::__invoke
* @see app/Http/Controllers/Api/App/Threads/ThreadListController.php:12
* @route '/api/app/v1/threads'
*/
index.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: index.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Api\App\Threads\ThreadListController::__invoke
* @see app/Http/Controllers/Api/App/Threads/ThreadListController.php:12
* @route '/api/app/v1/threads'
*/
index.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: index.url(options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\Api\App\Threads\NewThreadController::newMethod
* @see app/Http/Controllers/Api/App/Threads/NewThreadController.php:24
* @route '/api/app/v1/threads/new'
*/
export const newMethod = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: newMethod.url(options),
    method: 'get',
})

newMethod.definition = {
    methods: ["get","head"],
    url: '/api/app/v1/threads/new',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Api\App\Threads\NewThreadController::newMethod
* @see app/Http/Controllers/Api/App/Threads/NewThreadController.php:24
* @route '/api/app/v1/threads/new'
*/
newMethod.url = (options?: RouteQueryOptions) => {
    return newMethod.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Api\App\Threads\NewThreadController::newMethod
* @see app/Http/Controllers/Api/App/Threads/NewThreadController.php:24
* @route '/api/app/v1/threads/new'
*/
newMethod.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: newMethod.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Api\App\Threads\NewThreadController::newMethod
* @see app/Http/Controllers/Api/App/Threads/NewThreadController.php:24
* @route '/api/app/v1/threads/new'
*/
newMethod.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: newMethod.url(options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\Threads\RepositoryAccessController::__invoke
* @see app/Http/Controllers/Threads/RepositoryAccessController.php:15
* @route '/api/app/v1/threads/new/repository-access'
*/
export const repositoryAccess = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: repositoryAccess.url(options),
    method: 'get',
})

repositoryAccess.definition = {
    methods: ["get","head"],
    url: '/api/app/v1/threads/new/repository-access',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Threads\RepositoryAccessController::__invoke
* @see app/Http/Controllers/Threads/RepositoryAccessController.php:15
* @route '/api/app/v1/threads/new/repository-access'
*/
repositoryAccess.url = (options?: RouteQueryOptions) => {
    return repositoryAccess.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Threads\RepositoryAccessController::__invoke
* @see app/Http/Controllers/Threads/RepositoryAccessController.php:15
* @route '/api/app/v1/threads/new/repository-access'
*/
repositoryAccess.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: repositoryAccess.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Threads\RepositoryAccessController::__invoke
* @see app/Http/Controllers/Threads/RepositoryAccessController.php:15
* @route '/api/app/v1/threads/new/repository-access'
*/
repositoryAccess.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: repositoryAccess.url(options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\Api\App\Threads\NewThreadController::store
* @see app/Http/Controllers/Api/App/Threads/NewThreadController.php:32
* @route '/api/app/v1/threads'
*/
export const store = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: store.url(options),
    method: 'post',
})

store.definition = {
    methods: ["post"],
    url: '/api/app/v1/threads',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Api\App\Threads\NewThreadController::store
* @see app/Http/Controllers/Api/App/Threads/NewThreadController.php:32
* @route '/api/app/v1/threads'
*/
store.url = (options?: RouteQueryOptions) => {
    return store.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Api\App\Threads\NewThreadController::store
* @see app/Http/Controllers/Api/App/Threads/NewThreadController.php:32
* @route '/api/app/v1/threads'
*/
store.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: store.url(options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Api\App\Threads\ThreadController::show
* @see app/Http/Controllers/Api/App/Threads/ThreadController.php:14
* @route '/api/app/v1/threads/{thread}'
*/
export const show = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: show.url(args, options),
    method: 'get',
})

show.definition = {
    methods: ["get","head"],
    url: '/api/app/v1/threads/{thread}',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Api\App\Threads\ThreadController::show
* @see app/Http/Controllers/Api/App/Threads/ThreadController.php:14
* @route '/api/app/v1/threads/{thread}'
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
* @see \App\Http\Controllers\Api\App\Threads\ThreadController::show
* @see app/Http/Controllers/Api/App/Threads/ThreadController.php:14
* @route '/api/app/v1/threads/{thread}'
*/
show.get = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: show.url(args, options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Api\App\Threads\ThreadController::show
* @see app/Http/Controllers/Api/App/Threads/ThreadController.php:14
* @route '/api/app/v1/threads/{thread}'
*/
show.head = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: show.url(args, options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\Api\App\Threads\ThreadSeenController::__invoke
* @see app/Http/Controllers/Api/App/Threads/ThreadSeenController.php:14
* @route '/api/app/v1/threads/{thread}/seen'
*/
export const seen = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: seen.url(args, options),
    method: 'post',
})

seen.definition = {
    methods: ["post"],
    url: '/api/app/v1/threads/{thread}/seen',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Api\App\Threads\ThreadSeenController::__invoke
* @see app/Http/Controllers/Api/App/Threads/ThreadSeenController.php:14
* @route '/api/app/v1/threads/{thread}/seen'
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
* @see \App\Http\Controllers\Api\App\Threads\ThreadSeenController::__invoke
* @see app/Http/Controllers/Api/App/Threads/ThreadSeenController.php:14
* @route '/api/app/v1/threads/{thread}/seen'
*/
seen.post = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: seen.url(args, options),
    method: 'post',
})

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
* @see \App\Http\Controllers\Api\App\Threads\ThreadActionController::continueMethod
* @see app/Http/Controllers/Api/App/Threads/ThreadActionController.php:21
* @route '/api/app/v1/threads/{thread}/continue'
*/
export const continueMethod = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: continueMethod.url(args, options),
    method: 'post',
})

continueMethod.definition = {
    methods: ["post"],
    url: '/api/app/v1/threads/{thread}/continue',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Api\App\Threads\ThreadActionController::continueMethod
* @see app/Http/Controllers/Api/App/Threads/ThreadActionController.php:21
* @route '/api/app/v1/threads/{thread}/continue'
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
* @see \App\Http\Controllers\Api\App\Threads\ThreadActionController::continueMethod
* @see app/Http/Controllers/Api/App/Threads/ThreadActionController.php:21
* @route '/api/app/v1/threads/{thread}/continue'
*/
continueMethod.post = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: continueMethod.url(args, options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Api\App\Push\LiveActivityChannelController::__invoke
* @see app/Http/Controllers/Api/App/Push/LiveActivityChannelController.php:20
* @route '/api/app/v1/threads/{thread}/live-activity-channel'
*/
export const liveActivityChannel = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: liveActivityChannel.url(args, options),
    method: 'post',
})

liveActivityChannel.definition = {
    methods: ["post"],
    url: '/api/app/v1/threads/{thread}/live-activity-channel',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Api\App\Push\LiveActivityChannelController::__invoke
* @see app/Http/Controllers/Api/App/Push/LiveActivityChannelController.php:20
* @route '/api/app/v1/threads/{thread}/live-activity-channel'
*/
liveActivityChannel.url = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions) => {
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

    return liveActivityChannel.definition.url
            .replace('{thread}', parsedArgs.thread.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Api\App\Push\LiveActivityChannelController::__invoke
* @see app/Http/Controllers/Api/App/Push/LiveActivityChannelController.php:20
* @route '/api/app/v1/threads/{thread}/live-activity-channel'
*/
liveActivityChannel.post = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: liveActivityChannel.url(args, options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Api\App\Threads\ThreadTaskActionController::retry
* @see app/Http/Controllers/Api/App/Threads/ThreadTaskActionController.php:24
* @route '/api/app/v1/threads/{thread}/retry'
*/
export const retry = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: retry.url(args, options),
    method: 'post',
})

retry.definition = {
    methods: ["post"],
    url: '/api/app/v1/threads/{thread}/retry',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Api\App\Threads\ThreadTaskActionController::retry
* @see app/Http/Controllers/Api/App/Threads/ThreadTaskActionController.php:24
* @route '/api/app/v1/threads/{thread}/retry'
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
* @see \App\Http\Controllers\Api\App\Threads\ThreadTaskActionController::retry
* @see app/Http/Controllers/Api/App/Threads/ThreadTaskActionController.php:24
* @route '/api/app/v1/threads/{thread}/retry'
*/
retry.post = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: retry.url(args, options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Api\App\Threads\ThreadTaskActionController::setupAndContinue
* @see app/Http/Controllers/Api/App/Threads/ThreadTaskActionController.php:29
* @route '/api/app/v1/threads/{thread}/setup-and-continue'
*/
export const setupAndContinue = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: setupAndContinue.url(args, options),
    method: 'post',
})

setupAndContinue.definition = {
    methods: ["post"],
    url: '/api/app/v1/threads/{thread}/setup-and-continue',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Api\App\Threads\ThreadTaskActionController::setupAndContinue
* @see app/Http/Controllers/Api/App/Threads/ThreadTaskActionController.php:29
* @route '/api/app/v1/threads/{thread}/setup-and-continue'
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
* @see \App\Http\Controllers\Api\App\Threads\ThreadTaskActionController::setupAndContinue
* @see app/Http/Controllers/Api/App/Threads/ThreadTaskActionController.php:29
* @route '/api/app/v1/threads/{thread}/setup-and-continue'
*/
setupAndContinue.post = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: setupAndContinue.url(args, options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Api\App\Threads\ThreadTaskActionController::cancel
* @see app/Http/Controllers/Api/App/Threads/ThreadTaskActionController.php:34
* @route '/api/app/v1/threads/{thread}/cancel'
*/
export const cancel = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: cancel.url(args, options),
    method: 'post',
})

cancel.definition = {
    methods: ["post"],
    url: '/api/app/v1/threads/{thread}/cancel',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Api\App\Threads\ThreadTaskActionController::cancel
* @see app/Http/Controllers/Api/App/Threads/ThreadTaskActionController.php:34
* @route '/api/app/v1/threads/{thread}/cancel'
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
* @see \App\Http\Controllers\Api\App\Threads\ThreadTaskActionController::cancel
* @see app/Http/Controllers/Api/App/Threads/ThreadTaskActionController.php:34
* @route '/api/app/v1/threads/{thread}/cancel'
*/
cancel.post = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: cancel.url(args, options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Api\App\Threads\ThreadTaskActionController::move
* @see app/Http/Controllers/Api/App/Threads/ThreadTaskActionController.php:39
* @route '/api/app/v1/threads/{thread}/move'
*/
export const move = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: move.url(args, options),
    method: 'post',
})

move.definition = {
    methods: ["post"],
    url: '/api/app/v1/threads/{thread}/move',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Api\App\Threads\ThreadTaskActionController::move
* @see app/Http/Controllers/Api/App/Threads/ThreadTaskActionController.php:39
* @route '/api/app/v1/threads/{thread}/move'
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
* @see \App\Http\Controllers\Api\App\Threads\ThreadTaskActionController::move
* @see app/Http/Controllers/Api/App/Threads/ThreadTaskActionController.php:39
* @route '/api/app/v1/threads/{thread}/move'
*/
move.post = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: move.url(args, options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Api\App\Threads\ThreadTaskActionController::requestReview
* @see app/Http/Controllers/Api/App/Threads/ThreadTaskActionController.php:49
* @route '/api/app/v1/threads/{thread}/request-review'
*/
export const requestReview = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: requestReview.url(args, options),
    method: 'post',
})

requestReview.definition = {
    methods: ["post"],
    url: '/api/app/v1/threads/{thread}/request-review',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Api\App\Threads\ThreadTaskActionController::requestReview
* @see app/Http/Controllers/Api/App/Threads/ThreadTaskActionController.php:49
* @route '/api/app/v1/threads/{thread}/request-review'
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
* @see \App\Http\Controllers\Api\App\Threads\ThreadTaskActionController::requestReview
* @see app/Http/Controllers/Api/App/Threads/ThreadTaskActionController.php:49
* @route '/api/app/v1/threads/{thread}/request-review'
*/
requestReview.post = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: requestReview.url(args, options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Api\App\Threads\ThreadTaskActionController::retryRender
* @see app/Http/Controllers/Api/App/Threads/ThreadTaskActionController.php:60
* @route '/api/app/v1/threads/{thread}/retry-render'
*/
export const retryRender = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: retryRender.url(args, options),
    method: 'post',
})

retryRender.definition = {
    methods: ["post"],
    url: '/api/app/v1/threads/{thread}/retry-render',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Api\App\Threads\ThreadTaskActionController::retryRender
* @see app/Http/Controllers/Api/App/Threads/ThreadTaskActionController.php:60
* @route '/api/app/v1/threads/{thread}/retry-render'
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
* @see \App\Http\Controllers\Api\App\Threads\ThreadTaskActionController::retryRender
* @see app/Http/Controllers/Api/App/Threads/ThreadTaskActionController.php:60
* @route '/api/app/v1/threads/{thread}/retry-render'
*/
retryRender.post = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: retryRender.url(args, options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Api\App\Threads\ThreadRepositoryAnswerController::__invoke
* @see app/Http/Controllers/Api/App/Threads/ThreadRepositoryAnswerController.php:19
* @route '/api/app/v1/threads/{thread}/repository-answer'
*/
export const repositoryAnswer = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: repositoryAnswer.url(args, options),
    method: 'post',
})

repositoryAnswer.definition = {
    methods: ["post"],
    url: '/api/app/v1/threads/{thread}/repository-answer',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Api\App\Threads\ThreadRepositoryAnswerController::__invoke
* @see app/Http/Controllers/Api/App/Threads/ThreadRepositoryAnswerController.php:19
* @route '/api/app/v1/threads/{thread}/repository-answer'
*/
repositoryAnswer.url = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions) => {
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

    return repositoryAnswer.definition.url
            .replace('{thread}', parsedArgs.thread.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Api\App\Threads\ThreadRepositoryAnswerController::__invoke
* @see app/Http/Controllers/Api/App/Threads/ThreadRepositoryAnswerController.php:19
* @route '/api/app/v1/threads/{thread}/repository-answer'
*/
repositoryAnswer.post = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: repositoryAnswer.url(args, options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Api\App\Threads\ThreadManagementController::star
* @see app/Http/Controllers/Api/App/Threads/ThreadManagementController.php:24
* @route '/api/app/v1/threads/{thread}/star'
*/
export const star = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: star.url(args, options),
    method: 'post',
})

star.definition = {
    methods: ["post"],
    url: '/api/app/v1/threads/{thread}/star',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Api\App\Threads\ThreadManagementController::star
* @see app/Http/Controllers/Api/App/Threads/ThreadManagementController.php:24
* @route '/api/app/v1/threads/{thread}/star'
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
* @see \App\Http\Controllers\Api\App\Threads\ThreadManagementController::star
* @see app/Http/Controllers/Api/App/Threads/ThreadManagementController.php:24
* @route '/api/app/v1/threads/{thread}/star'
*/
star.post = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: star.url(args, options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Api\App\Threads\ThreadManagementController::close
* @see app/Http/Controllers/Api/App/Threads/ThreadManagementController.php:41
* @route '/api/app/v1/threads/{thread}/close'
*/
export const close = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: close.url(args, options),
    method: 'post',
})

close.definition = {
    methods: ["post"],
    url: '/api/app/v1/threads/{thread}/close',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Api\App\Threads\ThreadManagementController::close
* @see app/Http/Controllers/Api/App/Threads/ThreadManagementController.php:41
* @route '/api/app/v1/threads/{thread}/close'
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
* @see \App\Http\Controllers\Api\App\Threads\ThreadManagementController::close
* @see app/Http/Controllers/Api/App/Threads/ThreadManagementController.php:41
* @route '/api/app/v1/threads/{thread}/close'
*/
close.post = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: close.url(args, options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Api\App\Threads\ThreadManagementController::destroy
* @see app/Http/Controllers/Api/App/Threads/ThreadManagementController.php:48
* @route '/api/app/v1/threads/{thread}'
*/
export const destroy = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: destroy.url(args, options),
    method: 'delete',
})

destroy.definition = {
    methods: ["delete"],
    url: '/api/app/v1/threads/{thread}',
} satisfies RouteDefinition<["delete"]>

/**
* @see \App\Http\Controllers\Api\App\Threads\ThreadManagementController::destroy
* @see app/Http/Controllers/Api/App/Threads/ThreadManagementController.php:48
* @route '/api/app/v1/threads/{thread}'
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
* @see \App\Http\Controllers\Api\App\Threads\ThreadManagementController::destroy
* @see app/Http/Controllers/Api/App/Threads/ThreadManagementController.php:48
* @route '/api/app/v1/threads/{thread}'
*/
destroy.delete = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: destroy.url(args, options),
    method: 'delete',
})

const threads = {
    index: Object.assign(index, index),
    new: Object.assign(newMethod, newMethod),
    repositoryAccess: Object.assign(repositoryAccess, repositoryAccess),
    store: Object.assign(store, store),
    show: Object.assign(show, show),
    messages: Object.assign(messages, messages),
    clarificationAnswers: Object.assign(clarificationAnswers, clarificationAnswers),
    share: Object.assign(share, share),
    seen: Object.assign(seen, seen),
    stop: Object.assign(stop, stop),
    continue: Object.assign(continueMethod, continueMethod),
    restore: Object.assign(restore, restore),
    queuedMessages: Object.assign(queuedMessages, queuedMessages),
    events: Object.assign(events, events),
    diff: Object.assign(diff, diff),
    pullRequest: Object.assign(pullRequest, pullRequest),
    pullRequests: Object.assign(pullRequests, pullRequests),
    stepLimit: Object.assign(stepLimit, stepLimit),
    agents: Object.assign(agents, agents),
    stack: Object.assign(stack, stack),
    environment: Object.assign(environment, environment),
    repositories: Object.assign(repositories, repositories),
    preview: Object.assign(preview, preview),
    liveActivityChannel: Object.assign(liveActivityChannel, liveActivityChannel),
    terminalSessions: Object.assign(terminalSessions, terminalSessions),
    files: Object.assign(files, files),
    coOwner: Object.assign(coOwner, coOwner),
    retry: Object.assign(retry, retry),
    setupAndContinue: Object.assign(setupAndContinue, setupAndContinue),
    cancel: Object.assign(cancel, cancel),
    move: Object.assign(move, move),
    requestReview: Object.assign(requestReview, requestReview),
    retryRender: Object.assign(retryRender, retryRender),
    repositoryAnswer: Object.assign(repositoryAnswer, repositoryAnswer),
    star: Object.assign(star, star),
    close: Object.assign(close, close),
    destroy: Object.assign(destroy, destroy),
}

export default threads