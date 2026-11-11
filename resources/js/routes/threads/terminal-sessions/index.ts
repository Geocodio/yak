import { queryParams, type RouteQueryOptions, type RouteDefinition, applyUrlDefaults } from './../../../wayfinder'
/**
* @see \App\Http\Controllers\Threads\TerminalSessionController::store
* @see app/Http/Controllers/Threads/TerminalSessionController.php:29
* @route '/threads/{thread}/terminal-sessions'
*/
export const store = (args: { thread: string | number } | [thread: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: store.url(args, options),
    method: 'post',
})

store.definition = {
    methods: ["post"],
    url: '/threads/{thread}/terminal-sessions',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Threads\TerminalSessionController::store
* @see app/Http/Controllers/Threads/TerminalSessionController.php:29
* @route '/threads/{thread}/terminal-sessions'
*/
store.url = (args: { thread: string | number } | [thread: string | number ] | string | number, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { thread: args }
    }

    if (Array.isArray(args)) {
        args = {
            thread: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        thread: args.thread,
    }

    return store.definition.url
            .replace('{thread}', parsedArgs.thread.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Threads\TerminalSessionController::store
* @see app/Http/Controllers/Threads/TerminalSessionController.php:29
* @route '/threads/{thread}/terminal-sessions'
*/
store.post = (args: { thread: string | number } | [thread: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: store.url(args, options),
    method: 'post',
})

const terminalSessions = {
    store: Object.assign(store, store),
}

export default terminalSessions