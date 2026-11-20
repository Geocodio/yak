import { queryParams, type RouteQueryOptions, type RouteDefinition, applyUrlDefaults } from './../../../../../wayfinder'
/**
* @see \App\Http\Controllers\Threads\ThreadController::show
* @see app/Http/Controllers/Threads/ThreadController.php:22
* @route '/app/embed/threads/{thread}'
*/
const show7f9b3a562a452bfec7e80e3f8250aac7 = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: show7f9b3a562a452bfec7e80e3f8250aac7.url(args, options),
    method: 'get',
})

show7f9b3a562a452bfec7e80e3f8250aac7.definition = {
    methods: ["get","head"],
    url: '/app/embed/threads/{thread}',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Threads\ThreadController::show
* @see app/Http/Controllers/Threads/ThreadController.php:22
* @route '/app/embed/threads/{thread}'
*/
show7f9b3a562a452bfec7e80e3f8250aac7.url = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions) => {
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

    return show7f9b3a562a452bfec7e80e3f8250aac7.definition.url
            .replace('{thread}', parsedArgs.thread.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Threads\ThreadController::show
* @see app/Http/Controllers/Threads/ThreadController.php:22
* @route '/app/embed/threads/{thread}'
*/
show7f9b3a562a452bfec7e80e3f8250aac7.get = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: show7f9b3a562a452bfec7e80e3f8250aac7.url(args, options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Threads\ThreadController::show
* @see app/Http/Controllers/Threads/ThreadController.php:22
* @route '/app/embed/threads/{thread}'
*/
show7f9b3a562a452bfec7e80e3f8250aac7.head = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: show7f9b3a562a452bfec7e80e3f8250aac7.url(args, options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\Threads\ThreadController::show
* @see app/Http/Controllers/Threads/ThreadController.php:22
* @route '/threads/{thread}'
*/
const show6819ad8d694f9b52849e844933ad7258 = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: show6819ad8d694f9b52849e844933ad7258.url(args, options),
    method: 'get',
})

show6819ad8d694f9b52849e844933ad7258.definition = {
    methods: ["get","head"],
    url: '/threads/{thread}',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Threads\ThreadController::show
* @see app/Http/Controllers/Threads/ThreadController.php:22
* @route '/threads/{thread}'
*/
show6819ad8d694f9b52849e844933ad7258.url = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions) => {
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

    return show6819ad8d694f9b52849e844933ad7258.definition.url
            .replace('{thread}', parsedArgs.thread.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Threads\ThreadController::show
* @see app/Http/Controllers/Threads/ThreadController.php:22
* @route '/threads/{thread}'
*/
show6819ad8d694f9b52849e844933ad7258.get = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: show6819ad8d694f9b52849e844933ad7258.url(args, options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Threads\ThreadController::show
* @see app/Http/Controllers/Threads/ThreadController.php:22
* @route '/threads/{thread}'
*/
show6819ad8d694f9b52849e844933ad7258.head = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: show6819ad8d694f9b52849e844933ad7258.url(args, options),
    method: 'head',
})

/**
* Multiple routes resolve to \App\Http\Controllers\Threads\ThreadController::show, so this export is a
* dictionary keyed by URI rather than a callable. Call a specific route with `show['<uri>'](...)`,
* or import the route by name from your generated `routes/` directory.
*/
export const show = {
    '/app/embed/threads/{thread}': show7f9b3a562a452bfec7e80e3f8250aac7,
    '/threads/{thread}': show6819ad8d694f9b52849e844933ad7258,
}

const ThreadController = { show }

export default ThreadController