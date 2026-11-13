import { queryParams, type RouteQueryOptions, type RouteDefinition, applyUrlDefaults } from './../../../wayfinder'
/**
* @see \App\Http\Controllers\Threads\ThreadTeleportRequestController::store
* @see app/Http/Controllers/Threads/ThreadTeleportRequestController.php:21
* @route '/threads/{thread}/teleport-request'
*/
export const store = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: store.url(args, options),
    method: 'post',
})

store.definition = {
    methods: ["post"],
    url: '/threads/{thread}/teleport-request',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Threads\ThreadTeleportRequestController::store
* @see app/Http/Controllers/Threads/ThreadTeleportRequestController.php:21
* @route '/threads/{thread}/teleport-request'
*/
store.url = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions) => {
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

    return store.definition.url
            .replace('{thread}', parsedArgs.thread.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Threads\ThreadTeleportRequestController::store
* @see app/Http/Controllers/Threads/ThreadTeleportRequestController.php:21
* @route '/threads/{thread}/teleport-request'
*/
store.post = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: store.url(args, options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Threads\ThreadTeleportRequestController::destroy
* @see app/Http/Controllers/Threads/ThreadTeleportRequestController.php:38
* @route '/threads/{thread}/teleport-request'
*/
export const destroy = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: destroy.url(args, options),
    method: 'delete',
})

destroy.definition = {
    methods: ["delete"],
    url: '/threads/{thread}/teleport-request',
} satisfies RouteDefinition<["delete"]>

/**
* @see \App\Http\Controllers\Threads\ThreadTeleportRequestController::destroy
* @see app/Http/Controllers/Threads/ThreadTeleportRequestController.php:38
* @route '/threads/{thread}/teleport-request'
*/
destroy.url = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions) => {
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

    return destroy.definition.url
            .replace('{thread}', parsedArgs.thread.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Threads\ThreadTeleportRequestController::destroy
* @see app/Http/Controllers/Threads/ThreadTeleportRequestController.php:38
* @route '/threads/{thread}/teleport-request'
*/
destroy.delete = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: destroy.url(args, options),
    method: 'delete',
})

const teleportRequest = {
    store: Object.assign(store, store),
    destroy: Object.assign(destroy, destroy),
}

export default teleportRequest