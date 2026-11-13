import { queryParams, type RouteQueryOptions, type RouteDefinition, applyUrlDefaults } from './../../../../../wayfinder'
/**
* @see \App\Http\Controllers\Threads\ThreadEventController::index
* @see app/Http/Controllers/Threads/ThreadEventController.php:22
* @route '/threads/{thread}/events'
*/
export const index = (args: { thread: number | { id: number } } | [thread: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: index.url(args, options),
    method: 'get',
})

index.definition = {
    methods: ["get","head"],
    url: '/threads/{thread}/events',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Threads\ThreadEventController::index
* @see app/Http/Controllers/Threads/ThreadEventController.php:22
* @route '/threads/{thread}/events'
*/
index.url = (args: { thread: number | { id: number } } | [thread: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
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

    return index.definition.url
            .replace('{thread}', parsedArgs.thread.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Threads\ThreadEventController::index
* @see app/Http/Controllers/Threads/ThreadEventController.php:22
* @route '/threads/{thread}/events'
*/
index.get = (args: { thread: number | { id: number } } | [thread: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: index.url(args, options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Threads\ThreadEventController::index
* @see app/Http/Controllers/Threads/ThreadEventController.php:22
* @route '/threads/{thread}/events'
*/
index.head = (args: { thread: number | { id: number } } | [thread: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: index.url(args, options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\Threads\ThreadEventController::show
* @see app/Http/Controllers/Threads/ThreadEventController.php:51
* @route '/threads/{thread}/events/{sequence}'
*/
export const show = (args: { thread: number | { id: number }, sequence: string | number } | [thread: number | { id: number }, sequence: string | number ], options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: show.url(args, options),
    method: 'get',
})

show.definition = {
    methods: ["get","head"],
    url: '/threads/{thread}/events/{sequence}',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Threads\ThreadEventController::show
* @see app/Http/Controllers/Threads/ThreadEventController.php:51
* @route '/threads/{thread}/events/{sequence}'
*/
show.url = (args: { thread: number | { id: number }, sequence: string | number } | [thread: number | { id: number }, sequence: string | number ], options?: RouteQueryOptions) => {
    if (Array.isArray(args)) {
        args = {
            thread: args[0],
            sequence: args[1],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        thread: typeof args.thread === 'object'
        ? args.thread.id
        : args.thread,
        sequence: args.sequence,
    }

    return show.definition.url
            .replace('{thread}', parsedArgs.thread.toString())
            .replace('{sequence}', parsedArgs.sequence.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Threads\ThreadEventController::show
* @see app/Http/Controllers/Threads/ThreadEventController.php:51
* @route '/threads/{thread}/events/{sequence}'
*/
show.get = (args: { thread: number | { id: number }, sequence: string | number } | [thread: number | { id: number }, sequence: string | number ], options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: show.url(args, options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Threads\ThreadEventController::show
* @see app/Http/Controllers/Threads/ThreadEventController.php:51
* @route '/threads/{thread}/events/{sequence}'
*/
show.head = (args: { thread: number | { id: number }, sequence: string | number } | [thread: number | { id: number }, sequence: string | number ], options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: show.url(args, options),
    method: 'head',
})

const ThreadEventController = { index, show }

export default ThreadEventController