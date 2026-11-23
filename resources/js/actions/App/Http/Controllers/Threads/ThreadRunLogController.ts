import { queryParams, type RouteQueryOptions, type RouteDefinition, applyUrlDefaults } from './../../../../../wayfinder'
/**
* @see \App\Http\Controllers\Threads\ThreadRunLogController::show
* @see app/Http/Controllers/Threads/ThreadRunLogController.php:23
* @route '/threads/{thread}/runs/{run}/log'
*/
export const show = (args: { thread: string | { public_id: string }, run: string | number | { id: string | number } } | [thread: string | { public_id: string }, run: string | number | { id: string | number } ], options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: show.url(args, options),
    method: 'get',
})

show.definition = {
    methods: ["get","head"],
    url: '/threads/{thread}/runs/{run}/log',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Threads\ThreadRunLogController::show
* @see app/Http/Controllers/Threads/ThreadRunLogController.php:23
* @route '/threads/{thread}/runs/{run}/log'
*/
show.url = (args: { thread: string | { public_id: string }, run: string | number | { id: string | number } } | [thread: string | { public_id: string }, run: string | number | { id: string | number } ], options?: RouteQueryOptions) => {
    if (Array.isArray(args)) {
        args = {
            thread: args[0],
            run: args[1],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        thread: typeof args.thread === 'object'
        ? args.thread.public_id
        : args.thread,
        run: typeof args.run === 'object'
        ? args.run.id
        : args.run,
    }

    return show.definition.url
            .replace('{thread}', parsedArgs.thread.toString())
            .replace('{run}', parsedArgs.run.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Threads\ThreadRunLogController::show
* @see app/Http/Controllers/Threads/ThreadRunLogController.php:23
* @route '/threads/{thread}/runs/{run}/log'
*/
show.get = (args: { thread: string | { public_id: string }, run: string | number | { id: string | number } } | [thread: string | { public_id: string }, run: string | number | { id: string | number } ], options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: show.url(args, options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Threads\ThreadRunLogController::show
* @see app/Http/Controllers/Threads/ThreadRunLogController.php:23
* @route '/threads/{thread}/runs/{run}/log'
*/
show.head = (args: { thread: string | { public_id: string }, run: string | number | { id: string | number } } | [thread: string | { public_id: string }, run: string | number | { id: string | number } ], options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: show.url(args, options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\Threads\ThreadRunLogController::showTask
* @see app/Http/Controllers/Threads/ThreadRunLogController.php:30
* @route '/threads/{thread}/tasks/{task}/log'
*/
export const showTask = (args: { thread: string | { public_id: string }, task: string | { public_id: string } } | [thread: string | { public_id: string }, task: string | { public_id: string } ], options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: showTask.url(args, options),
    method: 'get',
})

showTask.definition = {
    methods: ["get","head"],
    url: '/threads/{thread}/tasks/{task}/log',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Threads\ThreadRunLogController::showTask
* @see app/Http/Controllers/Threads/ThreadRunLogController.php:30
* @route '/threads/{thread}/tasks/{task}/log'
*/
showTask.url = (args: { thread: string | { public_id: string }, task: string | { public_id: string } } | [thread: string | { public_id: string }, task: string | { public_id: string } ], options?: RouteQueryOptions) => {
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

    return showTask.definition.url
            .replace('{thread}', parsedArgs.thread.toString())
            .replace('{task}', parsedArgs.task.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Threads\ThreadRunLogController::showTask
* @see app/Http/Controllers/Threads/ThreadRunLogController.php:30
* @route '/threads/{thread}/tasks/{task}/log'
*/
showTask.get = (args: { thread: string | { public_id: string }, task: string | { public_id: string } } | [thread: string | { public_id: string }, task: string | { public_id: string } ], options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: showTask.url(args, options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Threads\ThreadRunLogController::showTask
* @see app/Http/Controllers/Threads/ThreadRunLogController.php:30
* @route '/threads/{thread}/tasks/{task}/log'
*/
showTask.head = (args: { thread: string | { public_id: string }, task: string | { public_id: string } } | [thread: string | { public_id: string }, task: string | { public_id: string } ], options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: showTask.url(args, options),
    method: 'head',
})

const ThreadRunLogController = { show, showTask }

export default ThreadRunLogController