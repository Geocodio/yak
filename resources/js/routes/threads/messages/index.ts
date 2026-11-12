import { queryParams, type RouteQueryOptions, type RouteDefinition, applyUrlDefaults } from './../../../wayfinder'
/**
* @see \App\Http\Controllers\Threads\ThreadMessageController::store
* @see app/Http/Controllers/Threads/ThreadMessageController.php:22
* @route '/threads/{thread}/messages'
*/
export const store = (args: { thread: number | { id: number } } | [thread: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: store.url(args, options),
    method: 'post',
})

store.definition = {
    methods: ["post"],
    url: '/threads/{thread}/messages',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Threads\ThreadMessageController::store
* @see app/Http/Controllers/Threads/ThreadMessageController.php:22
* @route '/threads/{thread}/messages'
*/
store.url = (args: { thread: number | { id: number } } | [thread: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
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

    return store.definition.url
            .replace('{thread}', parsedArgs.thread.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Threads\ThreadMessageController::store
* @see app/Http/Controllers/Threads/ThreadMessageController.php:22
* @route '/threads/{thread}/messages'
*/
store.post = (args: { thread: number | { id: number } } | [thread: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: store.url(args, options),
    method: 'post',
})

const messages = {
    store: Object.assign(store, store),
}

export default messages