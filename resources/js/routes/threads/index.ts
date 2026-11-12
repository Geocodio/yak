import { queryParams, type RouteQueryOptions, type RouteDefinition, applyUrlDefaults } from './../../wayfinder'
import messages from './messages'
import restore from './restore'
import diff from './diff'
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
* @see app/Http/Controllers/Threads/ThreadMessageController.php:74
* @route '/threads/{thread}/stop'
*/
export const stop = (args: { thread: number | { id: number } } | [thread: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: stop.url(args, options),
    method: 'post',
})

stop.definition = {
    methods: ["post"],
    url: '/threads/{thread}/stop',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Threads\ThreadMessageController::stop
* @see app/Http/Controllers/Threads/ThreadMessageController.php:74
* @route '/threads/{thread}/stop'
*/
stop.url = (args: { thread: number | { id: number } } | [thread: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
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

    return stop.definition.url
            .replace('{thread}', parsedArgs.thread.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Threads\ThreadMessageController::stop
* @see app/Http/Controllers/Threads/ThreadMessageController.php:74
* @route '/threads/{thread}/stop'
*/
stop.post = (args: { thread: number | { id: number } } | [thread: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: stop.url(args, options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Threads\ContinueTurnController::__invoke
* @see app/Http/Controllers/Threads/ContinueTurnController.php:19
* @route '/threads/{thread}/continue'
*/
export const continueMethod = (args: { thread: number | { id: number } } | [thread: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: continueMethod.url(args, options),
    method: 'post',
})

continueMethod.definition = {
    methods: ["post"],
    url: '/threads/{thread}/continue',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Threads\ContinueTurnController::__invoke
* @see app/Http/Controllers/Threads/ContinueTurnController.php:19
* @route '/threads/{thread}/continue'
*/
continueMethod.url = (args: { thread: number | { id: number } } | [thread: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
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

    return continueMethod.definition.url
            .replace('{thread}', parsedArgs.thread.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Threads\ContinueTurnController::__invoke
* @see app/Http/Controllers/Threads/ContinueTurnController.php:19
* @route '/threads/{thread}/continue'
*/
continueMethod.post = (args: { thread: number | { id: number } } | [thread: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: continueMethod.url(args, options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Threads\NewThreadController::__invoke
* @see app/Http/Controllers/Threads/NewThreadController.php:15
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
* @see app/Http/Controllers/Threads/NewThreadController.php:15
* @route '/threads/new'
*/
newMethod.url = (options?: RouteQueryOptions) => {
    return newMethod.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Threads\NewThreadController::__invoke
* @see app/Http/Controllers/Threads/NewThreadController.php:15
* @route '/threads/new'
*/
newMethod.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: newMethod.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Threads\NewThreadController::__invoke
* @see app/Http/Controllers/Threads/NewThreadController.php:15
* @route '/threads/new'
*/
newMethod.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: newMethod.url(options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\Threads\StoreThreadController::__invoke
* @see app/Http/Controllers/Threads/StoreThreadController.php:16
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
* @see app/Http/Controllers/Threads/StoreThreadController.php:16
* @route '/threads'
*/
store.url = (options?: RouteQueryOptions) => {
    return store.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Threads\StoreThreadController::__invoke
* @see app/Http/Controllers/Threads/StoreThreadController.php:16
* @route '/threads'
*/
store.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: store.url(options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Threads\ThreadController::show
* @see app/Http/Controllers/Threads/ThreadController.php:18
* @route '/threads/{thread}'
*/
export const show = (args: { thread: number | { id: number } } | [thread: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: show.url(args, options),
    method: 'get',
})

show.definition = {
    methods: ["get","head"],
    url: '/threads/{thread}',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Threads\ThreadController::show
* @see app/Http/Controllers/Threads/ThreadController.php:18
* @route '/threads/{thread}'
*/
show.url = (args: { thread: number | { id: number } } | [thread: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
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

    return show.definition.url
            .replace('{thread}', parsedArgs.thread.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Threads\ThreadController::show
* @see app/Http/Controllers/Threads/ThreadController.php:18
* @route '/threads/{thread}'
*/
show.get = (args: { thread: number | { id: number } } | [thread: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: show.url(args, options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Threads\ThreadController::show
* @see app/Http/Controllers/Threads/ThreadController.php:18
* @route '/threads/{thread}'
*/
show.head = (args: { thread: number | { id: number } } | [thread: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
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
export const seen = (args: { thread: number | { id: number } } | [thread: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
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
seen.url = (args: { thread: number | { id: number } } | [thread: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
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

    return seen.definition.url
            .replace('{thread}', parsedArgs.thread.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Threads\ThreadSeenController::__invoke
* @see app/Http/Controllers/Threads/ThreadSeenController.php:14
* @route '/threads/{thread}/seen'
*/
seen.post = (args: { thread: number | { id: number } } | [thread: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: seen.url(args, options),
    method: 'post',
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

const threads = {
    messages: Object.assign(messages, messages),
    stop: Object.assign(stop, stop),
    continue: Object.assign(continueMethod, continueMethod),
    restore: Object.assign(restore, restore),
    new: Object.assign(newMethod, newMethod),
    store: Object.assign(store, store),
    show: Object.assign(show, show),
    list: Object.assign(list, list),
    seen: Object.assign(seen, seen),
    diff: Object.assign(diff, diff),
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
    repositories: Object.assign(repositories, repositories),
    pullRequests: Object.assign(pullRequests, pullRequests),
}

export default threads