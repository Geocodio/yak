import { queryParams, type RouteQueryOptions, type RouteDefinition, applyUrlDefaults } from './../../../wayfinder'
/**
* @see \App\Http\Controllers\Threads\ThreadRunLogController::log
* @see app/Http/Controllers/Threads/ThreadRunLogController.php:20
* @route '/threads/{thread}/runs/{run}/log'
*/
export const log = (args: { thread: string | { public_id: string }, run: string | number | { id: string | number } } | [thread: string | { public_id: string }, run: string | number | { id: string | number } ], options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: log.url(args, options),
    method: 'get',
})

log.definition = {
    methods: ["get","head"],
    url: '/threads/{thread}/runs/{run}/log',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Threads\ThreadRunLogController::log
* @see app/Http/Controllers/Threads/ThreadRunLogController.php:20
* @route '/threads/{thread}/runs/{run}/log'
*/
log.url = (args: { thread: string | { public_id: string }, run: string | number | { id: string | number } } | [thread: string | { public_id: string }, run: string | number | { id: string | number } ], options?: RouteQueryOptions) => {
    if (Array.isArray(args)) {
        args = {
            thread: args[0],
            run: args[1],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        thread: typeof args.thread === 'object'
        ? args.thread.public_id
        : args.thread,
        run: typeof args.run === 'object'
        ? args.run.id
        : args.run,
    }

    return log.definition.url
            .replace('{thread}', parsedArgs.thread.toString())
            .replace('{run}', parsedArgs.run.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Threads\ThreadRunLogController::log
* @see app/Http/Controllers/Threads/ThreadRunLogController.php:20
* @route '/threads/{thread}/runs/{run}/log'
*/
log.get = (args: { thread: string | { public_id: string }, run: string | number | { id: string | number } } | [thread: string | { public_id: string }, run: string | number | { id: string | number } ], options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: log.url(args, options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Threads\ThreadRunLogController::log
* @see app/Http/Controllers/Threads/ThreadRunLogController.php:20
* @route '/threads/{thread}/runs/{run}/log'
*/
log.head = (args: { thread: string | { public_id: string }, run: string | number | { id: string | number } } | [thread: string | { public_id: string }, run: string | number | { id: string | number } ], options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: log.url(args, options),
    method: 'head',
})

const runs = {
    log: Object.assign(log, log),
}

export default runs