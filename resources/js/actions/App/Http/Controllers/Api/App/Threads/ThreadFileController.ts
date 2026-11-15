import { queryParams, type RouteQueryOptions, type RouteDefinition, applyUrlDefaults } from './../../../../../../../wayfinder'
/**
* @see \App\Http\Controllers\Api\App\Threads\ThreadFileController::tree
* @see app/Http/Controllers/Api/App/Threads/ThreadFileController.php:32
* @route '/api/app/v1/threads/{thread}/files'
*/
export const tree = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: tree.url(args, options),
    method: 'get',
})

tree.definition = {
    methods: ["get","head"],
    url: '/api/app/v1/threads/{thread}/files',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Api\App\Threads\ThreadFileController::tree
* @see app/Http/Controllers/Api/App/Threads/ThreadFileController.php:32
* @route '/api/app/v1/threads/{thread}/files'
*/
tree.url = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions) => {
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

    return tree.definition.url
            .replace('{thread}', parsedArgs.thread.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Api\App\Threads\ThreadFileController::tree
* @see app/Http/Controllers/Api/App/Threads/ThreadFileController.php:32
* @route '/api/app/v1/threads/{thread}/files'
*/
tree.get = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: tree.url(args, options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Api\App\Threads\ThreadFileController::tree
* @see app/Http/Controllers/Api/App/Threads/ThreadFileController.php:32
* @route '/api/app/v1/threads/{thread}/files'
*/
tree.head = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: tree.url(args, options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\Api\App\Threads\ThreadFileController::show
* @see app/Http/Controllers/Api/App/Threads/ThreadFileController.php:56
* @route '/api/app/v1/threads/{thread}/files/content'
*/
export const show = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: show.url(args, options),
    method: 'get',
})

show.definition = {
    methods: ["get","head"],
    url: '/api/app/v1/threads/{thread}/files/content',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Api\App\Threads\ThreadFileController::show
* @see app/Http/Controllers/Api/App/Threads/ThreadFileController.php:56
* @route '/api/app/v1/threads/{thread}/files/content'
*/
show.url = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions) => {
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

    return show.definition.url
            .replace('{thread}', parsedArgs.thread.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Api\App\Threads\ThreadFileController::show
* @see app/Http/Controllers/Api/App/Threads/ThreadFileController.php:56
* @route '/api/app/v1/threads/{thread}/files/content'
*/
show.get = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: show.url(args, options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Api\App\Threads\ThreadFileController::show
* @see app/Http/Controllers/Api/App/Threads/ThreadFileController.php:56
* @route '/api/app/v1/threads/{thread}/files/content'
*/
show.head = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: show.url(args, options),
    method: 'head',
})

const ThreadFileController = { tree, show }

export default ThreadFileController