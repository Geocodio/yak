import { queryParams, type RouteQueryOptions, type RouteDefinition, applyUrlDefaults } from './../../../../../wayfinder'
/**
* @see \App\Http\Controllers\Threads\ThreadAgentController::log
* @see app/Http/Controllers/Threads/ThreadAgentController.php:27
* @route '/threads/{thread}/agents/{agent}/log'
*/
export const log = (args: { thread: string | { public_id: string }, agent: string | { task_id: string } } | [thread: string | { public_id: string }, agent: string | { task_id: string } ], options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: log.url(args, options),
    method: 'get',
})

log.definition = {
    methods: ["get","head"],
    url: '/threads/{thread}/agents/{agent}/log',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Threads\ThreadAgentController::log
* @see app/Http/Controllers/Threads/ThreadAgentController.php:27
* @route '/threads/{thread}/agents/{agent}/log'
*/
log.url = (args: { thread: string | { public_id: string }, agent: string | { task_id: string } } | [thread: string | { public_id: string }, agent: string | { task_id: string } ], options?: RouteQueryOptions) => {
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

    return log.definition.url
            .replace('{thread}', parsedArgs.thread.toString())
            .replace('{agent}', parsedArgs.agent.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Threads\ThreadAgentController::log
* @see app/Http/Controllers/Threads/ThreadAgentController.php:27
* @route '/threads/{thread}/agents/{agent}/log'
*/
log.get = (args: { thread: string | { public_id: string }, agent: string | { task_id: string } } | [thread: string | { public_id: string }, agent: string | { task_id: string } ], options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: log.url(args, options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Threads\ThreadAgentController::log
* @see app/Http/Controllers/Threads/ThreadAgentController.php:27
* @route '/threads/{thread}/agents/{agent}/log'
*/
log.head = (args: { thread: string | { public_id: string }, agent: string | { task_id: string } } | [thread: string | { public_id: string }, agent: string | { task_id: string } ], options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: log.url(args, options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\Threads\ThreadAgentController::stopAll
* @see app/Http/Controllers/Threads/ThreadAgentController.php:45
* @route '/threads/{thread}/agents/stop'
*/
export const stopAll = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: stopAll.url(args, options),
    method: 'post',
})

stopAll.definition = {
    methods: ["post"],
    url: '/threads/{thread}/agents/stop',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Threads\ThreadAgentController::stopAll
* @see app/Http/Controllers/Threads/ThreadAgentController.php:45
* @route '/threads/{thread}/agents/stop'
*/
stopAll.url = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions) => {
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

    return stopAll.definition.url
            .replace('{thread}', parsedArgs.thread.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Threads\ThreadAgentController::stopAll
* @see app/Http/Controllers/Threads/ThreadAgentController.php:45
* @route '/threads/{thread}/agents/stop'
*/
stopAll.post = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: stopAll.url(args, options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Threads\ThreadAgentController::stop
* @see app/Http/Controllers/Threads/ThreadAgentController.php:34
* @route '/threads/{thread}/agents/{agent}/stop'
*/
export const stop = (args: { thread: string | { public_id: string }, agent: string | { task_id: string } } | [thread: string | { public_id: string }, agent: string | { task_id: string } ], options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: stop.url(args, options),
    method: 'post',
})

stop.definition = {
    methods: ["post"],
    url: '/threads/{thread}/agents/{agent}/stop',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Threads\ThreadAgentController::stop
* @see app/Http/Controllers/Threads/ThreadAgentController.php:34
* @route '/threads/{thread}/agents/{agent}/stop'
*/
stop.url = (args: { thread: string | { public_id: string }, agent: string | { task_id: string } } | [thread: string | { public_id: string }, agent: string | { task_id: string } ], options?: RouteQueryOptions) => {
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

    return stop.definition.url
            .replace('{thread}', parsedArgs.thread.toString())
            .replace('{agent}', parsedArgs.agent.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Threads\ThreadAgentController::stop
* @see app/Http/Controllers/Threads/ThreadAgentController.php:34
* @route '/threads/{thread}/agents/{agent}/stop'
*/
stop.post = (args: { thread: string | { public_id: string }, agent: string | { task_id: string } } | [thread: string | { public_id: string }, agent: string | { task_id: string } ], options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: stop.url(args, options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Threads\ThreadAgentController::message
* @see app/Http/Controllers/Threads/ThreadAgentController.php:58
* @route '/threads/{thread}/agents/{agent}/messages'
*/
export const message = (args: { thread: string | { public_id: string }, agent: string | { task_id: string } } | [thread: string | { public_id: string }, agent: string | { task_id: string } ], options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: message.url(args, options),
    method: 'post',
})

message.definition = {
    methods: ["post"],
    url: '/threads/{thread}/agents/{agent}/messages',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Threads\ThreadAgentController::message
* @see app/Http/Controllers/Threads/ThreadAgentController.php:58
* @route '/threads/{thread}/agents/{agent}/messages'
*/
message.url = (args: { thread: string | { public_id: string }, agent: string | { task_id: string } } | [thread: string | { public_id: string }, agent: string | { task_id: string } ], options?: RouteQueryOptions) => {
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

    return message.definition.url
            .replace('{thread}', parsedArgs.thread.toString())
            .replace('{agent}', parsedArgs.agent.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Threads\ThreadAgentController::message
* @see app/Http/Controllers/Threads/ThreadAgentController.php:58
* @route '/threads/{thread}/agents/{agent}/messages'
*/
message.post = (args: { thread: string | { public_id: string }, agent: string | { task_id: string } } | [thread: string | { public_id: string }, agent: string | { task_id: string } ], options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: message.url(args, options),
    method: 'post',
})

const ThreadAgentController = { log, stopAll, stop, message }

export default ThreadAgentController