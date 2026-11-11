import { queryParams, type RouteQueryOptions, type RouteDefinition, applyUrlDefaults } from './../../../../../wayfinder'
/**
* @see \App\Http\Controllers\Threads\ThreadFileController::tree
* @see app/Http/Controllers/Threads/ThreadFileController.php:28
* @route '/threads/{thread}/files'
*/
export const tree = (args: { thread: number | { id: number } } | [thread: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: tree.url(args, options),
    method: 'get',
})

tree.definition = {
    methods: ["get","head"],
    url: '/threads/{thread}/files',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Threads\ThreadFileController::tree
* @see app/Http/Controllers/Threads/ThreadFileController.php:28
* @route '/threads/{thread}/files'
*/
tree.url = (args: { thread: number | { id: number } } | [thread: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
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

    return tree.definition.url
            .replace('{thread}', parsedArgs.thread.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Threads\ThreadFileController::tree
* @see app/Http/Controllers/Threads/ThreadFileController.php:28
* @route '/threads/{thread}/files'
*/
tree.get = (args: { thread: number | { id: number } } | [thread: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: tree.url(args, options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Threads\ThreadFileController::tree
* @see app/Http/Controllers/Threads/ThreadFileController.php:28
* @route '/threads/{thread}/files'
*/
tree.head = (args: { thread: number | { id: number } } | [thread: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: tree.url(args, options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\Threads\ThreadFileController::show
* @see app/Http/Controllers/Threads/ThreadFileController.php:60
* @route '/threads/{thread}/files/content'
*/
export const show = (args: { thread: number | { id: number } } | [thread: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: show.url(args, options),
    method: 'get',
})

show.definition = {
    methods: ["get","head"],
    url: '/threads/{thread}/files/content',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Threads\ThreadFileController::show
* @see app/Http/Controllers/Threads/ThreadFileController.php:60
* @route '/threads/{thread}/files/content'
*/
show.url = (args: { thread: number | { id: number } } | [thread: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
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

    return show.definition.url
            .replace('{thread}', parsedArgs.thread.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Threads\ThreadFileController::show
* @see app/Http/Controllers/Threads/ThreadFileController.php:60
* @route '/threads/{thread}/files/content'
*/
show.get = (args: { thread: number | { id: number } } | [thread: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: show.url(args, options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Threads\ThreadFileController::show
* @see app/Http/Controllers/Threads/ThreadFileController.php:60
* @route '/threads/{thread}/files/content'
*/
show.head = (args: { thread: number | { id: number } } | [thread: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: show.url(args, options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\Threads\ThreadFileController::update
* @see app/Http/Controllers/Threads/ThreadFileController.php:86
* @route '/threads/{thread}/files/content'
*/
export const update = (args: { thread: number | { id: number } } | [thread: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'put'> => ({
    url: update.url(args, options),
    method: 'put',
})

update.definition = {
    methods: ["put"],
    url: '/threads/{thread}/files/content',
} satisfies RouteDefinition<["put"]>

/**
* @see \App\Http\Controllers\Threads\ThreadFileController::update
* @see app/Http/Controllers/Threads/ThreadFileController.php:86
* @route '/threads/{thread}/files/content'
*/
update.url = (args: { thread: number | { id: number } } | [thread: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
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

    return update.definition.url
            .replace('{thread}', parsedArgs.thread.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Threads\ThreadFileController::update
* @see app/Http/Controllers/Threads/ThreadFileController.php:86
* @route '/threads/{thread}/files/content'
*/
update.put = (args: { thread: number | { id: number } } | [thread: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'put'> => ({
    url: update.url(args, options),
    method: 'put',
})

const ThreadFileController = { tree, show, update }

export default ThreadFileController