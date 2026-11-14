import { queryParams, type RouteQueryOptions, type RouteDefinition, applyUrlDefaults } from './../../../../../wayfinder'
/**
* @see \App\Http\Controllers\Threads\ThreadEventController::index
* @see Users/codemonkey/projects/yak/.worktrees/next-gen/app/Http/Controllers/Threads/ThreadEventController.php:22
* @route '/threads/{thread}/events'
*/
export const index = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: index.url(args, options),
    method: 'get',
})

index.definition = {
    methods: ["get","head"],
    url: '/threads/{thread}/events',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Threads\ThreadEventController::index
* @see Users/codemonkey/projects/yak/.worktrees/next-gen/app/Http/Controllers/Threads/ThreadEventController.php:22
* @route '/threads/{thread}/events'
*/
index.url = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions) => {
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

    return index.definition.url
            .replace('{thread}', parsedArgs.thread.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Threads\ThreadEventController::index
* @see Users/codemonkey/projects/yak/.worktrees/next-gen/app/Http/Controllers/Threads/ThreadEventController.php:22
* @route '/threads/{thread}/events'
*/
index.get = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: index.url(args, options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Threads\ThreadEventController::index
* @see Users/codemonkey/projects/yak/.worktrees/next-gen/app/Http/Controllers/Threads/ThreadEventController.php:22
* @route '/threads/{thread}/events'
*/
index.head = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: index.url(args, options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\Threads\ThreadEventController::show
* @see Users/codemonkey/projects/yak/.worktrees/next-gen/app/Http/Controllers/Threads/ThreadEventController.php:51
* @route '/threads/{thread}/events/{sequence}'
*/
export const show = (args: { thread: string | { public_id: string }, sequence: string | number } | [thread: string | { public_id: string }, sequence: string | number ], options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: show.url(args, options),
    method: 'get',
})

show.definition = {
    methods: ["get","head"],
    url: '/threads/{thread}/events/{sequence}',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Threads\ThreadEventController::show
* @see Users/codemonkey/projects/yak/.worktrees/next-gen/app/Http/Controllers/Threads/ThreadEventController.php:51
* @route '/threads/{thread}/events/{sequence}'
*/
show.url = (args: { thread: string | { public_id: string }, sequence: string | number } | [thread: string | { public_id: string }, sequence: string | number ], options?: RouteQueryOptions) => {
    if (Array.isArray(args)) {
        args = {
            thread: args[0],
            sequence: args[1],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        thread: typeof args.thread === 'object'
        ? args.thread.public_id
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
* @see Users/codemonkey/projects/yak/.worktrees/next-gen/app/Http/Controllers/Threads/ThreadEventController.php:51
* @route '/threads/{thread}/events/{sequence}'
*/
show.get = (args: { thread: string | { public_id: string }, sequence: string | number } | [thread: string | { public_id: string }, sequence: string | number ], options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: show.url(args, options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Threads\ThreadEventController::show
* @see Users/codemonkey/projects/yak/.worktrees/next-gen/app/Http/Controllers/Threads/ThreadEventController.php:51
* @route '/threads/{thread}/events/{sequence}'
*/
show.head = (args: { thread: string | { public_id: string }, sequence: string | number } | [thread: string | { public_id: string }, sequence: string | number ], options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: show.url(args, options),
    method: 'head',
})

const ThreadEventController = { index, show }

export default ThreadEventController