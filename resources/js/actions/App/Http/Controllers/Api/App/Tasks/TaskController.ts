import { queryParams, type RouteQueryOptions, type RouteDefinition, applyUrlDefaults } from './../../../../../../../wayfinder'
/**
* @see \App\Http\Controllers\Api\App\Tasks\TaskController::show
* @see app/Http/Controllers/Api/App/Tasks/TaskController.php:13
* @route '/api/app/v1/tasks/{task}'
*/
export const show = (args: { task: string | { public_id: string } } | [task: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: show.url(args, options),
    method: 'get',
})

show.definition = {
    methods: ["get","head"],
    url: '/api/app/v1/tasks/{task}',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Api\App\Tasks\TaskController::show
* @see app/Http/Controllers/Api/App/Tasks/TaskController.php:13
* @route '/api/app/v1/tasks/{task}'
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
* @see \App\Http\Controllers\Api\App\Tasks\TaskController::show
* @see app/Http/Controllers/Api/App/Tasks/TaskController.php:13
* @route '/api/app/v1/tasks/{task}'
*/
show.get = (args: { task: string | { public_id: string } } | [task: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: show.url(args, options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Api\App\Tasks\TaskController::show
* @see app/Http/Controllers/Api/App/Tasks/TaskController.php:13
* @route '/api/app/v1/tasks/{task}'
*/
show.head = (args: { task: string | { public_id: string } } | [task: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: show.url(args, options),
    method: 'head',
})

const TaskController = { show }

export default TaskController