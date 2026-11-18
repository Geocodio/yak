import { queryParams, type RouteQueryOptions, type RouteDefinition, applyUrlDefaults } from './../../../wayfinder'
/**
* @see \App\Http\Controllers\Threads\ThreadRunLogController::log
* @see app/Http/Controllers/Threads/ThreadRunLogController.php:27
* @route '/threads/{thread}/tasks/{task}/log'
*/
export const log = (args: { thread: string | { public_id: string }, task: string | { public_id: string } } | [thread: string | { public_id: string }, task: string | { public_id: string } ], options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: log.url(args, options),
    method: 'get',
})

log.definition = {
    methods: ["get","head"],
    url: '/threads/{thread}/tasks/{task}/log',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Threads\ThreadRunLogController::log
* @see app/Http/Controllers/Threads/ThreadRunLogController.php:27
* @route '/threads/{thread}/tasks/{task}/log'
*/
log.url = (args: { thread: string | { public_id: string }, task: string | { public_id: string } } | [thread: string | { public_id: string }, task: string | { public_id: string } ], options?: RouteQueryOptions) => {
    if (Array.isArray(args)) {
        args = {
            thread: args[0],
            task: args[1],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        thread: typeof args.thread === 'object'
        ? args.thread.public_id
        : args.thread,
        task: typeof args.task === 'object'
        ? args.task.public_id
        : args.task,
    }

    return log.definition.url
            .replace('{thread}', parsedArgs.thread.toString())
            .replace('{task}', parsedArgs.task.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Threads\ThreadRunLogController::log
* @see app/Http/Controllers/Threads/ThreadRunLogController.php:27
* @route '/threads/{thread}/tasks/{task}/log'
*/
log.get = (args: { thread: string | { public_id: string }, task: string | { public_id: string } } | [thread: string | { public_id: string }, task: string | { public_id: string } ], options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: log.url(args, options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Threads\ThreadRunLogController::log
* @see app/Http/Controllers/Threads/ThreadRunLogController.php:27
* @route '/threads/{thread}/tasks/{task}/log'
*/
log.head = (args: { thread: string | { public_id: string }, task: string | { public_id: string } } | [thread: string | { public_id: string }, task: string | { public_id: string } ], options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: log.url(args, options),
    method: 'head',
})

const tasks = {
    log: Object.assign(log, log),
}

export default tasks