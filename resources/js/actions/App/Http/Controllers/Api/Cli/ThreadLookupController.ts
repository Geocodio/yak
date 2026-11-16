import { queryParams, type RouteQueryOptions, type RouteDefinition, applyUrlDefaults } from './../../../../../../wayfinder'
/**
* @see \App\Http\Controllers\Api\Cli\ThreadLookupController::bySession
* @see app/Http/Controllers/Api/Cli/ThreadLookupController.php:22
* @route '/api/cli/v1/threads/by-session/{sessionId}'
*/
export const bySession = (args: { sessionId: string | number } | [sessionId: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: bySession.url(args, options),
    method: 'get',
})

bySession.definition = {
    methods: ["get","head"],
    url: '/api/cli/v1/threads/by-session/{sessionId}',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Api\Cli\ThreadLookupController::bySession
* @see app/Http/Controllers/Api/Cli/ThreadLookupController.php:22
* @route '/api/cli/v1/threads/by-session/{sessionId}'
*/
bySession.url = (args: { sessionId: string | number } | [sessionId: string | number ] | string | number, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { sessionId: args }
    }

    if (Array.isArray(args)) {
        args = {
            sessionId: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        sessionId: args.sessionId,
    }

    return bySession.definition.url
            .replace('{sessionId}', parsedArgs.sessionId.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Api\Cli\ThreadLookupController::bySession
* @see app/Http/Controllers/Api/Cli/ThreadLookupController.php:22
* @route '/api/cli/v1/threads/by-session/{sessionId}'
*/
bySession.get = (args: { sessionId: string | number } | [sessionId: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: bySession.url(args, options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Api\Cli\ThreadLookupController::bySession
* @see app/Http/Controllers/Api/Cli/ThreadLookupController.php:22
* @route '/api/cli/v1/threads/by-session/{sessionId}'
*/
bySession.head = (args: { sessionId: string | number } | [sessionId: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: bySession.url(args, options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\Api\Cli\ThreadLookupController::show
* @see app/Http/Controllers/Api/Cli/ThreadLookupController.php:17
* @route '/api/cli/v1/threads/{thread}'
*/
export const show = (args: { thread: string | number } | [thread: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: show.url(args, options),
    method: 'get',
})

show.definition = {
    methods: ["get","head"],
    url: '/api/cli/v1/threads/{thread}',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Api\Cli\ThreadLookupController::show
* @see app/Http/Controllers/Api/Cli/ThreadLookupController.php:17
* @route '/api/cli/v1/threads/{thread}'
*/
show.url = (args: { thread: string | number } | [thread: string | number ] | string | number, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { thread: args }
    }

    if (Array.isArray(args)) {
        args = {
            thread: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        thread: args.thread,
    }

    return show.definition.url
            .replace('{thread}', parsedArgs.thread.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Api\Cli\ThreadLookupController::show
* @see app/Http/Controllers/Api/Cli/ThreadLookupController.php:17
* @route '/api/cli/v1/threads/{thread}'
*/
show.get = (args: { thread: string | number } | [thread: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: show.url(args, options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Api\Cli\ThreadLookupController::show
* @see app/Http/Controllers/Api/Cli/ThreadLookupController.php:17
* @route '/api/cli/v1/threads/{thread}'
*/
show.head = (args: { thread: string | number } | [thread: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: show.url(args, options),
    method: 'head',
})

const ThreadLookupController = { bySession, show }

export default ThreadLookupController