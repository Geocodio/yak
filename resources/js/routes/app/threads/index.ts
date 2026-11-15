import { queryParams, type RouteQueryOptions, type RouteDefinition, applyUrlDefaults } from './../../../wayfinder'
import messages from './messages'
import clarificationAnswers from './clarification-answers'
import queuedMessages from './queued-messages'
import events from './events'
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

const threads = {
    index: Object.assign(index, index),
    show: Object.assign(show, show),
    messages: Object.assign(messages, messages),
    clarificationAnswers: Object.assign(clarificationAnswers, clarificationAnswers),
    seen: Object.assign(seen, seen),
    queuedMessages: Object.assign(queuedMessages, queuedMessages),
    events: Object.assign(events, events),
}

export default threads