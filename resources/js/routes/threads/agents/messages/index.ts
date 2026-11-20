import { queryParams, type RouteQueryOptions, type RouteDefinition, applyUrlDefaults } from './../../../../wayfinder'
/**
* @see \App\Http\Controllers\Threads\ThreadAgentController::store
* @see app/Http/Controllers/Threads/ThreadAgentController.php:58
* @route '/threads/{thread}/agents/{agent}/messages'
*/
export const store = (args: { thread: string | { public_id: string }, agent: string | { task_id: string } } | [thread: string | { public_id: string }, agent: string | { task_id: string } ], options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: store.url(args, options),
    method: 'post',
})

store.definition = {
    methods: ["post"],
    url: '/threads/{thread}/agents/{agent}/messages',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Threads\ThreadAgentController::store
* @see app/Http/Controllers/Threads/ThreadAgentController.php:58
* @route '/threads/{thread}/agents/{agent}/messages'
*/
store.url = (args: { thread: string | { public_id: string }, agent: string | { task_id: string } } | [thread: string | { public_id: string }, agent: string | { task_id: string } ], options?: RouteQueryOptions) => {
    if (Array.isArray(args)) {
        args = {
            thread: args[0],
            agent: args[1],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        thread: typeof args.thread === 'object'
        ? args.thread.public_id
        : args.thread,
        agent: typeof args.agent === 'object'
        ? args.agent.task_id
        : args.agent,
    }

    return store.definition.url
            .replace('{thread}', parsedArgs.thread.toString())
            .replace('{agent}', parsedArgs.agent.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Threads\ThreadAgentController::store
* @see app/Http/Controllers/Threads/ThreadAgentController.php:58
* @route '/threads/{thread}/agents/{agent}/messages'
*/
store.post = (args: { thread: string | { public_id: string }, agent: string | { task_id: string } } | [thread: string | { public_id: string }, agent: string | { task_id: string } ], options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: store.url(args, options),
    method: 'post',
})

const messages = {
    store: Object.assign(store, store),
}

export default messages