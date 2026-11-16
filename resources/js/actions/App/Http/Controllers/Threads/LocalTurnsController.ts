import { queryParams, type RouteQueryOptions, type RouteDefinition, applyUrlDefaults } from './../../../../../wayfinder'
/**
* @see \App\Http\Controllers\Threads\LocalTurnsController::__invoke
* @see app/Http/Controllers/Threads/LocalTurnsController.php:14
* @route '/threads/{thread}/local-turns'
*/
const LocalTurnsController = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: LocalTurnsController.url(args, options),
    method: 'get',
})

LocalTurnsController.definition = {
    methods: ["get","head"],
    url: '/threads/{thread}/local-turns',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Threads\LocalTurnsController::__invoke
* @see app/Http/Controllers/Threads/LocalTurnsController.php:14
* @route '/threads/{thread}/local-turns'
*/
LocalTurnsController.url = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions) => {
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

    return LocalTurnsController.definition.url
            .replace('{thread}', parsedArgs.thread.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Threads\LocalTurnsController::__invoke
* @see app/Http/Controllers/Threads/LocalTurnsController.php:14
* @route '/threads/{thread}/local-turns'
*/
LocalTurnsController.get = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: LocalTurnsController.url(args, options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Threads\LocalTurnsController::__invoke
* @see app/Http/Controllers/Threads/LocalTurnsController.php:14
* @route '/threads/{thread}/local-turns'
*/
LocalTurnsController.head = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: LocalTurnsController.url(args, options),
    method: 'head',
})

export default LocalTurnsController