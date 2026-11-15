import { queryParams, type RouteQueryOptions, type RouteDefinition, applyUrlDefaults } from './../../../wayfinder'
import messages from './messages'
import clarificationAnswers from './clarification-answers'
import share from './share'
import restore from './restore'
import queuedMessages from './queued-messages'
import events from './events'
import stack from './stack'
import environment from './environment'
import repositories from './repositories'
import preview from './preview'
import terminalSessions from './terminal-sessions'
import files from './files'
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
* @see app/Http/Controllers/Api/App/Threads/NewThreadController.php:21
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
* @see app/Http/Controllers/Api/App/Threads/NewThreadController.php:21
* @route '/api/app/v1/threads/new'
*/
newMethod.url = (options?: RouteQueryOptions) => {
    return newMethod.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Api\App\Threads\NewThreadController::newMethod
* @see app/Http/Controllers/Api/App/Threads/NewThreadController.php:21
* @route '/api/app/v1/threads/new'
*/
newMethod.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: newMethod.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Api\App\Threads\NewThreadController::newMethod
* @see app/Http/Controllers/Api/App/Threads/NewThreadController.php:21
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
* @see app/Http/Controllers/Api/App/Threads/NewThreadController.php:29
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
* @see app/Http/Controllers/Api/App/Threads/NewThreadController.php:29
* @route '/api/app/v1/threads'
*/
store.url = (options?: RouteQueryOptions) => {
    return store.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Api\App\Threads\NewThreadController::store
* @see app/Http/Controllers/Api/App/Threads/NewThreadController.php:29
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
    stack: Object.assign(stack, stack),
    environment: Object.assign(environment, environment),
    repositories: Object.assign(repositories, repositories),
    preview: Object.assign(preview, preview),
    terminalSessions: Object.assign(terminalSessions, terminalSessions),
    files: Object.assign(files, files),
}

export default threads