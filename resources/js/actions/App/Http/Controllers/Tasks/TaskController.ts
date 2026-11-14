import { queryParams, type RouteQueryOptions, type RouteDefinition, applyUrlDefaults } from './../../../../../wayfinder'
/**
* @see \App\Http\Controllers\Tasks\TaskController::show
* @see Users/codemonkey/projects/yak/.worktrees/next-gen/app/Http/Controllers/Tasks/TaskController.php:20
* @route '/tasks/{task}'
*/
export const show = (args: { task: string | { public_id: string } } | [task: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: show.url(args, options),
    method: 'get',
})

show.definition = {
    methods: ["get","head"],
    url: '/tasks/{task}',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Tasks\TaskController::show
* @see Users/codemonkey/projects/yak/.worktrees/next-gen/app/Http/Controllers/Tasks/TaskController.php:20
* @route '/tasks/{task}'
*/
show.url = (args: { task: string | { public_id: string } } | [task: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { task: args }
    }

    if (typeof args === 'object' && !Array.isArray(args) && 'public_id' in args) {
        args = { task: args.public_id }
    }

    if (Array.isArray(args)) {
        args = {
            task: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        task: typeof args.task === 'object'
        ? args.task.public_id
        : args.task,
    }

    return show.definition.url
            .replace('{task}', parsedArgs.task.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Tasks\TaskController::show
* @see Users/codemonkey/projects/yak/.worktrees/next-gen/app/Http/Controllers/Tasks/TaskController.php:20
* @route '/tasks/{task}'
*/
show.get = (args: { task: string | { public_id: string } } | [task: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: show.url(args, options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Tasks\TaskController::show
* @see Users/codemonkey/projects/yak/.worktrees/next-gen/app/Http/Controllers/Tasks/TaskController.php:20
* @route '/tasks/{task}'
*/
show.head = (args: { task: string | { public_id: string } } | [task: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: show.url(args, options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\Tasks\TaskController::logEntries
* @see Users/codemonkey/projects/yak/.worktrees/next-gen/app/Http/Controllers/Tasks/TaskController.php:69
* @route '/tasks/{task}/log-entries'
*/
export const logEntries = (args: { task: string | { public_id: string } } | [task: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: logEntries.url(args, options),
    method: 'get',
})

logEntries.definition = {
    methods: ["get","head"],
    url: '/tasks/{task}/log-entries',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Tasks\TaskController::logEntries
* @see Users/codemonkey/projects/yak/.worktrees/next-gen/app/Http/Controllers/Tasks/TaskController.php:69
* @route '/tasks/{task}/log-entries'
*/
logEntries.url = (args: { task: string | { public_id: string } } | [task: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { task: args }
    }

    if (typeof args === 'object' && !Array.isArray(args) && 'public_id' in args) {
        args = { task: args.public_id }
    }

    if (Array.isArray(args)) {
        args = {
            task: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        task: typeof args.task === 'object'
        ? args.task.public_id
        : args.task,
    }

    return logEntries.definition.url
            .replace('{task}', parsedArgs.task.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Tasks\TaskController::logEntries
* @see Users/codemonkey/projects/yak/.worktrees/next-gen/app/Http/Controllers/Tasks/TaskController.php:69
* @route '/tasks/{task}/log-entries'
*/
logEntries.get = (args: { task: string | { public_id: string } } | [task: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: logEntries.url(args, options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Tasks\TaskController::logEntries
* @see Users/codemonkey/projects/yak/.worktrees/next-gen/app/Http/Controllers/Tasks/TaskController.php:69
* @route '/tasks/{task}/log-entries'
*/
logEntries.head = (args: { task: string | { public_id: string } } | [task: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: logEntries.url(args, options),
    method: 'head',
})

const TaskController = { show, logEntries }

export default TaskController