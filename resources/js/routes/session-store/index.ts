import { queryParams, type RouteQueryOptions, type RouteDefinition, applyUrlDefaults } from './../../wayfinder'
/**
* @see \App\Http\Controllers\Internal\SessionStoreController::append
* @see app/Http/Controllers/Internal/SessionStoreController.php:16
* @route '/internal/session-store/{thread}/append'
*/
export const append = (args: { thread: string | number | { id: string | number } } | [thread: string | number | { id: string | number } ] | string | number | { id: string | number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: append.url(args, options),
    method: 'post',
})

append.definition = {
    methods: ["post"],
    url: '/internal/session-store/{thread}/append',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Internal\SessionStoreController::append
* @see app/Http/Controllers/Internal/SessionStoreController.php:16
* @route '/internal/session-store/{thread}/append'
*/
append.url = (args: { thread: string | number | { id: string | number } } | [thread: string | number | { id: string | number } ] | string | number | { id: string | number }, options?: RouteQueryOptions) => {
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

    return append.definition.url
            .replace('{thread}', parsedArgs.thread.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Internal\SessionStoreController::append
* @see app/Http/Controllers/Internal/SessionStoreController.php:16
* @route '/internal/session-store/{thread}/append'
*/
append.post = (args: { thread: string | number | { id: string | number } } | [thread: string | number | { id: string | number } ] | string | number | { id: string | number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: append.url(args, options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Internal\SessionStoreController::length
* @see app/Http/Controllers/Internal/SessionStoreController.php:51
* @route '/internal/session-store/{thread}/length'
*/
export const length = (args: { thread: string | number | { id: string | number } } | [thread: string | number | { id: string | number } ] | string | number | { id: string | number }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: length.url(args, options),
    method: 'get',
})

length.definition = {
    methods: ["get","head"],
    url: '/internal/session-store/{thread}/length',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Internal\SessionStoreController::length
* @see app/Http/Controllers/Internal/SessionStoreController.php:51
* @route '/internal/session-store/{thread}/length'
*/
length.url = (args: { thread: string | number | { id: string | number } } | [thread: string | number | { id: string | number } ] | string | number | { id: string | number }, options?: RouteQueryOptions) => {
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

    return length.definition.url
            .replace('{thread}', parsedArgs.thread.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Internal\SessionStoreController::length
* @see app/Http/Controllers/Internal/SessionStoreController.php:51
* @route '/internal/session-store/{thread}/length'
*/
length.get = (args: { thread: string | number | { id: string | number } } | [thread: string | number | { id: string | number } ] | string | number | { id: string | number }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: length.url(args, options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Internal\SessionStoreController::length
* @see app/Http/Controllers/Internal/SessionStoreController.php:51
* @route '/internal/session-store/{thread}/length'
*/
length.head = (args: { thread: string | number | { id: string | number } } | [thread: string | number | { id: string | number } ] | string | number | { id: string | number }, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: length.url(args, options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\Internal\SessionStoreController::checkpoint
* @see app/Http/Controllers/Internal/SessionStoreController.php:61
* @route '/internal/session-store/{thread}/checkpoint'
*/
export const checkpoint = (args: { thread: string | number | { id: string | number } } | [thread: string | number | { id: string | number } ] | string | number | { id: string | number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: checkpoint.url(args, options),
    method: 'post',
})

checkpoint.definition = {
    methods: ["post"],
    url: '/internal/session-store/{thread}/checkpoint',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Internal\SessionStoreController::checkpoint
* @see app/Http/Controllers/Internal/SessionStoreController.php:61
* @route '/internal/session-store/{thread}/checkpoint'
*/
checkpoint.url = (args: { thread: string | number | { id: string | number } } | [thread: string | number | { id: string | number } ] | string | number | { id: string | number }, options?: RouteQueryOptions) => {
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

    return checkpoint.definition.url
            .replace('{thread}', parsedArgs.thread.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Internal\SessionStoreController::checkpoint
* @see app/Http/Controllers/Internal/SessionStoreController.php:61
* @route '/internal/session-store/{thread}/checkpoint'
*/
checkpoint.post = (args: { thread: string | number | { id: string | number } } | [thread: string | number | { id: string | number } ] | string | number | { id: string | number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: checkpoint.url(args, options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Internal\SessionStoreController::file
* @see app/Http/Controllers/Internal/SessionStoreController.php:77
* @route '/internal/session-store/{thread}/file'
*/
export const file = (args: { thread: string | number | { id: string | number } } | [thread: string | number | { id: string | number } ] | string | number | { id: string | number }, options?: RouteQueryOptions): RouteDefinition<'put'> => ({
    url: file.url(args, options),
    method: 'put',
})

file.definition = {
    methods: ["put"],
    url: '/internal/session-store/{thread}/file',
} satisfies RouteDefinition<["put"]>

/**
* @see \App\Http\Controllers\Internal\SessionStoreController::file
* @see app/Http/Controllers/Internal/SessionStoreController.php:77
* @route '/internal/session-store/{thread}/file'
*/
file.url = (args: { thread: string | number | { id: string | number } } | [thread: string | number | { id: string | number } ] | string | number | { id: string | number }, options?: RouteQueryOptions) => {
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

    return file.definition.url
            .replace('{thread}', parsedArgs.thread.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Internal\SessionStoreController::file
* @see app/Http/Controllers/Internal/SessionStoreController.php:77
* @route '/internal/session-store/{thread}/file'
*/
file.put = (args: { thread: string | number | { id: string | number } } | [thread: string | number | { id: string | number } ] | string | number | { id: string | number }, options?: RouteQueryOptions): RouteDefinition<'put'> => ({
    url: file.url(args, options),
    method: 'put',
})

const sessionStore = {
    append: Object.assign(append, append),
    length: Object.assign(length, length),
    checkpoint: Object.assign(checkpoint, checkpoint),
    file: Object.assign(file, file),
}

export default sessionStore