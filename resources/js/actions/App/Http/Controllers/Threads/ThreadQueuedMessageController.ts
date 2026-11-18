import { queryParams, type RouteQueryOptions, type RouteDefinition, applyUrlDefaults } from './../../../../../wayfinder'
/**
* @see \App\Http\Controllers\Threads\ThreadQueuedMessageController::update
* @see app/Http/Controllers/Threads/ThreadQueuedMessageController.php:23
* @route '/threads/{thread}/queued-messages/{message}'
*/
export const update = (args: { thread: string | { public_id: string }, message: string | number } | [thread: string | { public_id: string }, message: string | number ], options?: RouteQueryOptions): RouteDefinition<'patch'> => ({
    url: update.url(args, options),
    method: 'patch',
})

update.definition = {
    methods: ["patch"],
    url: '/threads/{thread}/queued-messages/{message}',
} satisfies RouteDefinition<["patch"]>

/**
* @see \App\Http\Controllers\Threads\ThreadQueuedMessageController::update
* @see app/Http/Controllers/Threads/ThreadQueuedMessageController.php:23
* @route '/threads/{thread}/queued-messages/{message}'
*/
update.url = (args: { thread: string | { public_id: string }, message: string | number } | [thread: string | { public_id: string }, message: string | number ], options?: RouteQueryOptions) => {
    if (Array.isArray(args)) {
        args = {
            thread: args[0],
            message: args[1],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        thread: typeof args.thread === 'object'
        ? args.thread.public_id
        : args.thread,
        message: args.message,
    }

    return update.definition.url
            .replace('{thread}', parsedArgs.thread.toString())
            .replace('{message}', parsedArgs.message.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Threads\ThreadQueuedMessageController::update
* @see app/Http/Controllers/Threads/ThreadQueuedMessageController.php:23
* @route '/threads/{thread}/queued-messages/{message}'
*/
update.patch = (args: { thread: string | { public_id: string }, message: string | number } | [thread: string | { public_id: string }, message: string | number ], options?: RouteQueryOptions): RouteDefinition<'patch'> => ({
    url: update.url(args, options),
    method: 'patch',
})

/**
* @see \App\Http\Controllers\Threads\ThreadQueuedMessageController::destroy
* @see app/Http/Controllers/Threads/ThreadQueuedMessageController.php:33
* @route '/threads/{thread}/queued-messages/{message}'
*/
export const destroy = (args: { thread: string | { public_id: string }, message: string | number } | [thread: string | { public_id: string }, message: string | number ], options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: destroy.url(args, options),
    method: 'delete',
})

destroy.definition = {
    methods: ["delete"],
    url: '/threads/{thread}/queued-messages/{message}',
} satisfies RouteDefinition<["delete"]>

/**
* @see \App\Http\Controllers\Threads\ThreadQueuedMessageController::destroy
* @see app/Http/Controllers/Threads/ThreadQueuedMessageController.php:33
* @route '/threads/{thread}/queued-messages/{message}'
*/
destroy.url = (args: { thread: string | { public_id: string }, message: string | number } | [thread: string | { public_id: string }, message: string | number ], options?: RouteQueryOptions) => {
    if (Array.isArray(args)) {
        args = {
            thread: args[0],
            message: args[1],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        thread: typeof args.thread === 'object'
        ? args.thread.public_id
        : args.thread,
        message: args.message,
    }

    return destroy.definition.url
            .replace('{thread}', parsedArgs.thread.toString())
            .replace('{message}', parsedArgs.message.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Threads\ThreadQueuedMessageController::destroy
* @see app/Http/Controllers/Threads/ThreadQueuedMessageController.php:33
* @route '/threads/{thread}/queued-messages/{message}'
*/
destroy.delete = (args: { thread: string | { public_id: string }, message: string | number } | [thread: string | { public_id: string }, message: string | number ], options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: destroy.url(args, options),
    method: 'delete',
})

const ThreadQueuedMessageController = { update, destroy }

export default ThreadQueuedMessageController